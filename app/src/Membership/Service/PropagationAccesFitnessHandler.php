<?php

declare(strict_types=1);

namespace App\Membership\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use App\Membership\Enum\TermRenewalMode;
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
            $this->syncEnd($abonnement, $droit);
        }

        $this->em->flush();
    }

    /**
     * LA FIN DE VALIDITÉ DU DROIT EST CELLE DE L'ABONNEMENT (décision de Maxime du 07/10).
     *
     * Une formule qui s'arrête au terme (`suspendre`) finit le jour de sa fin d'engagement à minuit,
     * l'instant où `SubscriptionTermHandler` la rend échue. Les autres se prolongent au terme : elles
     * n'ont pas de fin, et seuls la résiliation, l'impayé ou la pause les coupent, par le statut.
     *
     * Portée par le droit, la fin part dans le snapshot (`validiteFin`) : une borne hors ligne refuse
     * au terme sans attendre la synchronisation qui lui apprendrait l'échéance. Rappelée à chaque
     * propagation et à chaque prolongation, elle suit une pause ou un mode de formule changé depuis.
     * Le flush revient à l'appelant.
     */
    public function syncEnd(Membership $abonnement, ?DroitAcces $droit = null): void
    {
        $droit ??= $this->em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => $abonnement])?->getDroitAcces();
        $droit?->setFenetreFin(TermRenewalMode::forFormule($abonnement->getFormule()) === TermRenewalMode::Suspend
            ? $abonnement->getDateFinEngagement()->setTime(0, 0)
            : null);
    }
}
