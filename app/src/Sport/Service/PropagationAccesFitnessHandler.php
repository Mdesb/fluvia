<?php

declare(strict_types=1);

namespace App\Sport\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Sport\Entity\AbonnementFitness;
use App\Sport\Entity\StatutAccesFitness;
use App\Sport\Enum\MotifInactiviteAccesFitness;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Seul point d'écriture Sport sur `DroitAcces.statutProjection` (§0 point 2 du plan). Aucun fichier
 * `App\Acces\*` n'est modifié : Sport devient un second producteur légitime de cette transition,
 * exactement comme M2 (« devalide consomme une dévalidation M2 »). Le hors-ligne/synchro (CA-9/CA-10)
 * est hérité intégralement du mécanisme générique L3 — aucun développement supplémentaire ici.
 */
final class PropagationAccesFitnessHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Restaure l'accès (statut abonnement redevenu conforme). */
    public function activer(AbonnementFitness $abonnement): void
    {
        $this->appliquer($abonnement, true, null);
    }

    /** Coupe l'accès avec un motif métier Sport (non porté par L3). */
    public function desactiver(AbonnementFitness $abonnement, MotifInactiviteAccesFitness $motif): void
    {
        $this->appliquer($abonnement, false, $motif);
    }

    private function appliquer(AbonnementFitness $abonnement, bool $actif, ?MotifInactiviteAccesFitness $motif): void
    {
        $statutAcces = $this->em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement]);
        if (!$statutAcces instanceof StatutAccesFitness) {
            return;
        }

        $statutAcces->setActif($actif)
            ->setMotifInactivite($motif)
            ->setDateDernierePropagation(new \DateTimeImmutable());

        $droit = $statutAcces->getDroitAcces();
        if ($droit instanceof DroitAcces) {
            $droit->setStatutProjection($actif ? StatutProjectionDroit::Valide : StatutProjectionDroit::Devalide);
        }

        $this->em->flush();
    }
}
