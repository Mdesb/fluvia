<?php

declare(strict_types=1);

namespace App\Membership\EventListener;

use App\Membership\Compta\Port\ProjectionEcritureSepaInterface;
use App\Membership\Entity\Membership;
use App\Membership\Entity\StatutAccesFitness;
use App\Membership\Enum\MotifInactiviteAccesFitness;
use App\Membership\Enum\MembershipStatus;
use App\Membership\Recouvrement\AbonnementFitnessRedevablePort;
use App\Recouvrement\Event\AccesRedevableChangeEvent;
use App\Recouvrement\Event\IncidentImpayeDetecteEvent;
use App\Recouvrement\Event\IncidentImpayeReouvertureForceeEvent;
use App\Recouvrement\Event\IncidentImpayeResoluEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Uid\Uuid;

/**
 * Recâble `App\Sport` au moteur de recouvrement partagé (`App\Recouvrement`, refactor extraction) :
 * réagit aux événements génériques du moteur pour tenir à jour le statut métier propre à Sport
 * (`Membership.statut`, `StatutAccesFitness.actif/motifInactivite`) et la projection comptable
 * SEPA (`ProjectionEcritureSepaInterface`, Risque n°1 du plan-sport — toujours aucun fichier
 * `App\Compta\*` modifié). Le moteur générique lui-même ne connaît rien de Sport (aucune référence
 * fitness dans `App\Recouvrement`).
 */
final class SynchroniserImpayeFitnessListener
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ProjectionEcritureSepaInterface $projectionCompta,
    ) {
    }

    #[AsEventListener]
    public function surIncidentDetecte(IncidentImpayeDetecteEvent $event): void
    {
        if ($event->incident->getTypeRedevable() !== AbonnementFitnessRedevablePort::TYPE) {
            return;
        }
        $abonnement = $this->resoudreAbonnement($event->incident->getReferenceRedevable());
        if (!$abonnement instanceof Membership) {
            return;
        }

        $abonnement->setStatut(MembershipStatus::Unpaid);
        $this->em->flush();

        $this->projectionCompta->enregistrerImpaye($abonnement->getEtablissement()->getId(), $abonnement->getId(), $event->montantCentimes, $event->date);
    }

    #[AsEventListener]
    public function surIncidentResolu(IncidentImpayeResoluEvent $event): void
    {
        if ($event->incident->getTypeRedevable() !== AbonnementFitnessRedevablePort::TYPE) {
            return;
        }
        $abonnement = $this->resoudreAbonnement($event->incident->getReferenceRedevable());
        if (!$abonnement instanceof Membership) {
            return;
        }

        $abonnement->setStatut(MembershipStatus::Active);
        $this->em->flush();

        $this->projectionCompta->enregistrerEncaissement($abonnement->getEtablissement()->getId(), $abonnement->getId(), $event->montantCentimes, $event->date, $event->origine);
    }

    /** Réouverture forcée (RG-SOCLE-07) : réactive l'abonnement SANS encaissement (dossier reste ouvert). */
    #[AsEventListener]
    public function surReouvertureForcee(IncidentImpayeReouvertureForceeEvent $event): void
    {
        if ($event->incident->getTypeRedevable() !== AbonnementFitnessRedevablePort::TYPE) {
            return;
        }
        $abonnement = $this->resoudreAbonnement($event->incident->getReferenceRedevable());
        if (!$abonnement instanceof Membership) {
            return;
        }

        $abonnement->setStatut(MembershipStatus::Active);
        $this->em->flush();
    }

    #[AsEventListener]
    public function surAccesChange(AccesRedevableChangeEvent $event): void
    {
        if ($event->typeRedevable !== AbonnementFitnessRedevablePort::TYPE) {
            return;
        }
        if (!Uuid::isValid($event->referenceRedevable)) {
            return;
        }
        $statutAcces = $this->em->getRepository(StatutAccesFitness::class)->findOneBy(['abonnement' => Uuid::fromString($event->referenceRedevable)]);
        if (!$statutAcces instanceof StatutAccesFitness) {
            return;
        }

        $statutAcces->setActif($event->actif)
            ->setMotifInactivite($event->actif ? null : MotifInactiviteAccesFitness::Impaye)
            ->setDateDernierePropagation(new \DateTimeImmutable());
        $this->em->flush();
    }

    private function resoudreAbonnement(string $referenceRedevable): ?Membership
    {
        if (!Uuid::isValid($referenceRedevable)) {
            return null;
        }

        return $this->em->getRepository(Membership::class)->find($referenceRedevable);
    }
}
