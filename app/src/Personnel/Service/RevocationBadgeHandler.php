<?php

declare(strict_types=1);

namespace App\Personnel\Service;

use App\Acces\Entity\DeclarationPerteVol;
use App\Acces\Entity\Support;
use App\Acces\Service\BlocageSupportHandler;
use App\Personnel\Entity\BadgeStaff;
use App\Personnel\Enum\StatutBadgeStaff;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Révocation/suspension/réactivation d'un badge staff (RG-PERSO-08, CA-10/11, §4.8 spec, décision n°7
 * du plan) : réutilise **telle quelle** `App\Acces\Service\BlocageSupportHandler::bloquer()`/
 * `annuler()` pour les **quatre** déclencheurs (fin de contrat, suspension, perte/vol, révocation
 * manuelle) — seul le `motif` texte diffère. `BadgeStaff.statut` (3 états) est dénormalisé côté
 * Personnel ; `suspendu`/`revoque` mappent tous deux sur `Support.statut = bloque` côté Acces.
 */
final class RevocationBadgeHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly BlocageSupportHandler $blocageSupportHandler,
    ) {
    }

    public function revoquer(BadgeStaff $badge, string $motif, Utilisateur $agent): BadgeStaff
    {
        return $this->bloquer($badge, $motif, $agent, StatutBadgeStaff::Revoque);
    }

    public function suspendre(BadgeStaff $badge, string $motif, Utilisateur $agent): BadgeStaff
    {
        return $this->bloquer($badge, $motif, $agent, StatutBadgeStaff::Suspendu);
    }

    /**
     * Réactivation (uniquement depuis `suspendu`, réversible sans ré-appairage — décision n°7). Une
     * révocation est définitive (nécessite un nouveau badge, §4.8 spec).
     */
    public function reactiver(BadgeStaff $badge, Utilisateur $agent): BadgeStaff
    {
        if ($badge->getStatut() !== StatutBadgeStaff::Suspendu) {
            throw new ConflictHttpException('Seul un badge suspendu peut être réactivé (une révocation est définitive, §4.8 spec).');
        }

        $support = $badge->getSupport();
        if ($support instanceof Support) {
            $declaration = $this->em->getRepository(DeclarationPerteVol::class)
                ->findOneBy(['support' => $support, 'annulee' => false], ['horodatage' => 'DESC']);
            if ($declaration instanceof DeclarationPerteVol) {
                $this->blocageSupportHandler->annuler($declaration, $agent);
            }
        }

        $badge->setStatut(StatutBadgeStaff::Actif)->setDateRevocation(null)->setMotifRevocation(null);
        $this->em->flush();

        return $badge;
    }

    private function bloquer(BadgeStaff $badge, string $motif, Utilisateur $agent, StatutBadgeStaff $statutCible): BadgeStaff
    {
        $support = $badge->getSupport();
        if ($support instanceof Support) {
            $this->blocageSupportHandler->bloquer($support, $motif, $agent);
        }

        $badge->setStatut($statutCible)
            ->setDateRevocation(new \DateTimeImmutable())
            ->setMotifRevocation($motif);
        $this->em->flush();

        return $badge;
    }
}
