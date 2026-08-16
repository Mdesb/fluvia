<?php

declare(strict_types=1);

namespace App\Boutique\Service;

use App\Boutique\Entity\BilletQrMeta;
use App\Boutique\Entity\RetraitClickCollect;
use App\Boutique\Enum\StatutRetraitClickCollect;
use App\Boutique\Enum\StatutRetraitPhysique;
use App\Securite\Entity\Utilisateur;
use App\Vente\Port\AppairageAccesInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Retrait / click & collect (US-L8-13, RG-M3-18) : appaire le support physique via
 * `App\Vente\Port\AppairageAccesInterface` (frontière déjà posée en L2, réutilisée) — le QR
 * provisoire est remplacé pour l'usage courant, sans être invalidé (⚠ Risque n°10 du plan).
 */
final class ClickCollectHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly AppairageAccesInterface $appairage,
    ) {
    }

    public function valider(RetraitClickCollect $retrait, ?Utilisateur $operateur, ?string $identifiantSupportPhysique): void
    {
        if ($retrait->getStatut() === StatutRetraitClickCollect::Retire) {
            throw new ConflictHttpException('Ce retrait a déjà été traité.');
        }

        $support = $retrait->getBilletSupport();
        \assert($support !== null);
        if ($identifiantSupportPhysique !== null && trim($identifiantSupportPhysique) !== '') {
            $support->setIdentifiantSupport($identifiantSupportPhysique);
        }
        $this->appairage->appairer($support);

        $retrait->setStatut(StatutRetraitClickCollect::Retire)
            ->setDateRetrait(new \DateTimeImmutable())
            ->setTraitePar($operateur);

        $meta = $this->em->getRepository(BilletQrMeta::class)->findOneBy(['billetSupport' => $support]);
        if ($meta instanceof BilletQrMeta) {
            $meta->setStatutRetraitPhysique(StatutRetraitPhysique::Retire);
        }

        $this->em->flush();
    }
}
