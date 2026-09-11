<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Point d'écriture Sport sur `DroitAcces.statutProjection` pour les motifs propres à Sport (pause,
 * résiliation, et rejeu de l'état courant lors du rattachement d'un droit d'accès). Le motif « impayé »
 * est désormais piloté par le moteur de recouvrement partagé
 * (`App\Recouvrement\Service\PropagationAccesHandler`, refactor extraction) : Sport ne fait qu'observer
 * ses conséquences via `App\Membership\EventListener\SynchroniserImpayeFitnessListener`. Aucun fichier
 * `App\Acces\*` n'est modifié : Sport et Recouvrement sont deux producteurs légitimes de cette
 * transition, exactement comme M2 (« devalide consomme une dévalidation M2 »). Le hors-ligne/synchro
 * (CA-9/CA-10) est hérité intégralement du mécanisme générique L3 — aucun développement supplémentaire
 * ici.
 */
final class PropagationAccesFitnessHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Restaure l'accès (statut abonnement redevenu conforme). */
    public function activer(Membership $abonnement): void
    {
        $this->appliquer($abonnement, true, null);
    }

    /** Coupe l'accès avec un motif métier Sport (non porté par L3). */
    public function desactiver(Membership $abonnement, MotifInactiviteAccesFitness $motif): void
    {
        $this->appliquer($abonnement, false, $motif);
    }

    private function appliquer(Membership $abonnement, bool $actif, ?MotifInactiviteAccesFitness $motif): void
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
