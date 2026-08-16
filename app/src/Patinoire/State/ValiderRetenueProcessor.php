<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Patinoire\Entity\CautionLocationPatins;
use App\Patinoire\Entity\RetenueCaution;
use App\Patinoire\Enum\StatutCaution as StatutCautionLocation;
use App\Vente\Service\LecteurCorps;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\Uid\Uuid;

/**
 * Validation d'une retenue de caution (POST /patinoire/retenues/{id}/valider, US-PATIN-04, CA-4).
 * Corps : { "montantRetenu"?: decimal }. Garde-fou (§3 du plan, pas de Voter dédié) : si le montant
 * soumis diffère du montant par défaut proposé par la grille, la validation exige
 * `patinoire.forcer_retenue` (403 sinon) et positionne `forcee=true` — action journalisée (agent,
 * motif, horodatage, RG-SOCLE-07). Génère la trace comptable (`mouvementRegieRef`, référence logique
 * non-FK, même patron que `CautionCasier.regieMouvementRef`).
 *
 * @implements ProcessorInterface<RetenueCaution, RetenueCaution>
 */
final class ValiderRetenueProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly LecteurCorps $lecteur,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): RetenueCaution
    {
        \assert($data instanceof RetenueCaution);

        if ($data->estValidee()) {
            throw new ConflictHttpException('Cette retenue a déjà été validée.');
        }

        $corps = $this->lecteur->corps();
        $montantDefaut = $data->getMontantRetenu();
        $montantSoumis = isset($corps['montantRetenu']) ? number_format((float) $corps['montantRetenu'], 2, '.', '') : $montantDefaut;

        $forcee = $this->versCentimes($montantSoumis) !== $this->versCentimes($montantDefaut);
        if ($forcee && !$this->security->isGranted('PERM', 'patinoire.forcer_retenue')) {
            throw new AccessDeniedHttpException('Un montant différent du barème de la grille de retenue requiert le droit patinoire.forcer_retenue (RG-SOCLE-07).');
        }

        $agent = $this->security->getUser();
        $data->setMontantRetenu($montantSoumis)
            ->setForcee($forcee)
            ->setAgent($agent instanceof \App\Securite\Entity\Utilisateur ? $agent : null)
            ->setMouvementRegieRef(Uuid::v4());

        $caution = $this->em->getRepository(CautionLocationPatins::class)->findOneBy(['location' => $data->getLocation()]);
        if ($caution instanceof CautionLocationPatins) {
            $totale = $this->versCentimes($montantSoumis) >= $this->versCentimes($caution->getMontant());
            $caution->setStatut($totale ? StatutCautionLocation::RetenueTotale : StatutCautionLocation::RetenuePartielle);
        }

        $this->em->flush();

        return $data;
    }

    /**
     * Convertit un montant décimal (chaîne, ex. "15.00") en centimes entiers, pour une comparaison
     * exacte sans dépendre de l'extension `ext-bcmath` (non installée dans ce lot) ni de flottants.
     * ⚠ Suppose un montant positif ou nul (garanti par `Assert\PositiveOrZero` sur les entités montant).
     */
    private function versCentimes(string $montant): int
    {
        $parties = explode('.', trim($montant), 2);
        $entier = (int) $parties[0];
        $decimales = (int) str_pad(substr($parties[1] ?? '0', 0, 2), 2, '0');

        return $entier * 100 + $decimales;
    }
}
