<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Enum\StatutProjectionDroit;
use App\Acces\Enum\TypeDroitAcces;
use App\Reservation\Entity\ProjectionAccesReservation;
use App\Reservation\Entity\Reservation;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Projette un droit d'accès réel (RG-M5-12, CA-15 spec-reservation ; RG-ACC3-01..07) sur la fenêtre du
 * créneau, déclenché à la confirmation d'une réservation dont la Ressource porte `ouvreAcces=true`.
 * Construction directe d'un `App\Acces\Entity\DroitAcces` (`sourceType = TypeDroitAcces::Booking`), hors
 * `ProjectionDroitInterface` — même patron que `App\Personnel\Service\EmissionBadgeStaffHandler`
 * (`TypeDroitAcces::Personnel`). Révocation symétrique via `revoquerSiProjete()`, même patron que
 * `App\Recouvrement\Service\PropagationAccesHandler` / `App\Sport\Service\PropagationAccesFitnessHandler`
 * — aucun fichier `App\Acces\*` n'est modifié pour ce comportement.
 */
final class ProjectionAccesReservationHandler
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** RG-ACC3-01/02/03/04 : find-or-create idempotent, protégé par verrou pessimiste sur la Reservation. */
    public function projeterSiApplicable(Reservation $reservation): ?ProjectionAccesReservation
    {
        $creneau = $reservation->getCreneau();
        $ressource = $creneau?->getRessource();
        if ($creneau === null || $ressource === null || !$ressource->isOuvreAcces()) {
            return null;
        }

        /** @var ?ProjectionAccesReservation $resultat */
        $resultat = $this->em->wrapInTransaction(function () use ($reservation, $creneau): ?ProjectionAccesReservation {
            // Verrou pessimiste (RG-ACC3-04, risque §9 spec) : sérialise les rejeux concurrents pour
            // la même réservation ; revérification du find-or-create après obtention du verrou.
            $this->em->lock($reservation, LockMode::PESSIMISTIC_WRITE);
            // Relit l'état COMMITTÉ sous verrou : le processor appelant a `flush()` la réservation AVANT
            // d'appeler cette méthode, laissant une fenêtre où une annulation concurrente a pu la sortir
            // de `occupePlace()`. Sans ce refresh, l'entité en mémoire resterait `Confirmee` (identity map).
            $this->em->refresh($reservation);

            // RG-ACC3-01 : ne projeter QUE si la réservation occupe encore la place. Ferme la course
            // annulation↔projection qui, sinon, créerait un DroitAcces `Valide` pour une réservation déjà
            // annulée — accès fantôme permanent (plus aucun chemin ne rappellerait la révocation).
            if (!$reservation->getStatut()->occupePlace()) {
                return null;
            }

            $projection = $this->em->getRepository(ProjectionAccesReservation::class)
                ->findOneBy(['reservation' => $reservation]);

            $droit = null;
            if ($projection instanceof ProjectionAccesReservation && $projection->getDroitAccesRef() !== null) {
                $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
            }

            if (!$droit instanceof DroitAcces) {
                $droit = new DroitAcces();
                $droit->setSourceType(TypeDroitAcces::Booking)
                    ->setReservationRef($reservation->getId());
                $this->em->persist($droit);
            }

            // RG-ACC3-02 : fenêtre = créneau (marges non alimentées à ce jour, cf. §2 exclusions spec —
            // ResolveurMarges applique un défaut 0, fenêtre stricte). creditRestant/produitRef = null
            // (pas de décompte, pas de Produit M1). statutProjection revalidé à Valide même en mise à
            // jour (rejeu défensif d'une réservation toujours confirmée).
            $droit->setFenetreDebut($creneau->getDebut())
                ->setFenetreFin($creneau->getFin())
                ->setCreditRestant(null)
                ->setProduitRef(null)
                ->setEtablissement($reservation->getEtablissement())
                ->setStatutProjection(StatutProjectionDroit::Valide)
                ->setSynchroniseLe(new \DateTimeImmutable());

            if (!$projection instanceof ProjectionAccesReservation) {
                $projection = new ProjectionAccesReservation();
                $projection->setReservation($reservation);
                $this->em->persist($projection);
            }
            $projection->setFenetreDebut($creneau->getDebut())
                ->setFenetreFin($creneau->getFin())
                ->setEtablissement($reservation->getEtablissement())
                ->setDroitAccesRef($droit->getId());

            $this->em->flush();

            return $projection;
        });

        return $resultat;
    }

    /**
     * RG-ACC3-05 : dévalide le DroitAcces projeté (s'il existe) quand la Réservation quitte
     * `occupePlace()`. No-op silencieux si aucune projection (Ressource `ouvreAcces=false` ou jamais
     * projetée) — pas une erreur (§8 cas limite spec).
     */
    public function revoquerSiProjete(Reservation $reservation): void
    {
        // Même verrou pessimiste que `projeterSiApplicable()` : révocation et (re)projection d'une même
        // réservation deviennent mutuellement exclusives — sans quoi une projection concurrente pourrait
        // réécrire `Valide` juste après qu'une révocation ait posé `Devalide` (inversion d'ordre).
        $this->em->wrapInTransaction(function () use ($reservation): void {
            $this->em->lock($reservation, LockMode::PESSIMISTIC_WRITE);

            $projection = $this->em->getRepository(ProjectionAccesReservation::class)
                ->findOneBy(['reservation' => $reservation]);
            if (!$projection instanceof ProjectionAccesReservation || $projection->getDroitAccesRef() === null) {
                return;
            }

            $droit = $this->em->getRepository(DroitAcces::class)->find($projection->getDroitAccesRef());
            if ($droit instanceof DroitAcces) {
                $droit->setStatutProjection(StatutProjectionDroit::Devalide);
                $this->em->flush();
            }
        });
    }
}
