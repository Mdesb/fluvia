<?php

declare(strict_types=1);

namespace App\SmartFlow\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutCreneau;
use App\Reservation\Enum\StatutReservation;
use App\SmartFlow\Dto\ReservationSnapshot;
use App\SmartFlow\Dto\SlotSnapshot;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Lecture croisée en lecture seule de `Creneau`/`Ressource`/`Reservation` (`App\Reservation`) —
 * RG-SF-17, dérogation en lecture seule ⚠ à confirmer claude-A (plan-smart-flow.md §0.3, risque n°2).
 *
 * Ce service **ne modifie jamais** rien dans `App\Reservation` et **ne rend jamais** une entité
 * Doctrine d'un autre module hors de ce service — uniquement des DTO immuables `SlotSnapshot`/
 * `ReservationSnapshot` (`App\SmartFlow\Dto`). Justification de la dérogation (au lieu d'un provider
 * API Platform consommé par sous-requête HTTP) : la donnée est déjà exposée en lecture au même niveau
 * de permission (`reservation.lire`) par `Creneau`/`Ressource` (`#[ApiResource]` déjà filtrés par
 * `App\Reservation\Doctrine\PerimetreReservationExtension`) — ce service évite seulement le coût d'une
 * sous-requête HTTP pour la même donnée, il ne crée aucune fuite de périmètre nouvelle. Précédent
 * direct : `App\Finance` référence directement `Fournisseur`/`CommandeAchat`/`ArticleStock` (Stock) via
 * relation Doctrine (plan-supplier-invoices.md §0.2/§1).
 *
 * Toute méthode revérifie systématiquement que l'établissement résolu correspond à l'établissement
 * attendu (RG-SF-16) : un identifiant hors périmètre (ou introuvable) est traité comme une donnée
 * absente — retour `null`/liste vide, **jamais** une exception. C'est l'échec fermé silencieux exigé
 * par RG-SF-16 : ce service est appelé depuis un abonné d'événement synchrone (D7), qui ne doit jamais
 * casser la transaction de l'émetteur `Reservation` (`AnnulerReservationProcessor`/
 * `BasculerNoShowCommand`) à cause d'une incohérence qui ne concerne que Smart Flow.
 */
final class ReservationSlotReader
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    /** Revalide que le créneau appartient bien à `$establishmentId` avant de rendre un résultat (RG-SF-16). */
    public function snapshotCreneau(Uuid $slotId, Uuid $establishmentId): ?SlotSnapshot
    {
        $creneau = $this->em->getRepository(Creneau::class)->find($slotId);
        if (!$creneau instanceof Creneau) {
            return null;
        }

        return $this->toSlotSnapshot($creneau, $establishmentId);
    }

    /**
     * Créneaux ouverts (`planifie`) dans la fenêtre `[$from, $until]`, sur l'établissement attendu
     * uniquement (RG-SF-16) — la capacité résiduelle n'est pas filtrée ici : cette lecture reste neutre,
     * la décision « compatible » (RG-SF-09) est laissée à `CompatibleSlotFinder`.
     *
     * @return list<SlotSnapshot>
     */
    public function findCandidateSlots(Uuid $establishmentId, \DateTimeImmutable $from, \DateTimeImmutable $until): array
    {
        /** @var list<Creneau> $creneaux */
        $creneaux = $this->em->getRepository(Creneau::class)->createQueryBuilder('c')
            ->andWhere('c.etablissement = :etablissement')
            ->andWhere('c.debut >= :from')
            ->andWhere('c.debut <= :until')
            ->andWhere('c.statut = :statut')
            ->setParameter('etablissement', $establishmentId, 'uuid')
            ->setParameter('from', $from)
            ->setParameter('until', $until)
            ->setParameter('statut', StatutCreneau::Planifie->value)
            ->getQuery()
            ->getResult();

        $snapshots = [];
        foreach ($creneaux as $creneau) {
            $snapshot = $this->toSlotSnapshot($creneau, $establishmentId);
            if ($snapshot !== null) {
                $snapshots[] = $snapshot;
            }
        }

        return $snapshots;
    }

    /** Revalide que la réservation appartient bien à `$establishmentId` avant de rendre un résultat (RG-SF-16). */
    public function snapshotReservation(Uuid $reservationId, Uuid $establishmentId): ?ReservationSnapshot
    {
        $reservation = $this->em->getRepository(Reservation::class)->find($reservationId);
        if (!$reservation instanceof Reservation) {
            return null;
        }

        $etablissement = $reservation->getEtablissement();
        if ($etablissement === null || !$etablissement->getId()->equals($establishmentId)) {
            return null;
        }

        return new ReservationSnapshot(
            id: $reservation->getId(),
            establishmentId: $etablissement->getId(),
            customerId: $reservation->getOrganisateur()?->getId(),
            slotId: $reservation->getCreneau()?->getId(),
        );
    }

    /**
     * Revalide qu'une `Ressource` appartient bien à `$establishmentId` (RG-SF-16, plan-smart-flow.md §3
     * point 4 — `CreateSlotWaitlistEntryProcessor` revérifie ainsi le `resourceId` fourni par le client
     * avant persistance, IDOR). `false` pour une ressource introuvable ou hors périmètre — jamais
     * d'exception.
     */
    public function resourceExists(Uuid $resourceId, Uuid $establishmentId): bool
    {
        $ressource = $this->em->getRepository(Ressource::class)->find($resourceId);
        if (!$ressource instanceof Ressource) {
            return false;
        }

        $etablissement = $ressource->getEtablissement();

        return $etablissement !== null && $etablissement->getId()->equals($establishmentId);
    }

    private function toSlotSnapshot(Creneau $creneau, Uuid $establishmentId): ?SlotSnapshot
    {
        $etablissement = $creneau->getEtablissement();
        if ($etablissement === null || !$etablissement->getId()->equals($establishmentId)) {
            return null;
        }

        $ressource = $creneau->getRessource();

        $occupees = (int) $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.creneau = :creneau')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->setParameter('statuts', [StatutReservation::AConfirmer->value, StatutReservation::Confirmee->value, StatutReservation::Honoree->value])
            ->getQuery()
            ->getSingleScalarResult();

        return new SlotSnapshot(
            id: $creneau->getId(),
            establishmentId: $etablissement->getId(),
            resourceId: $ressource?->getId(),
            codeType: $ressource?->getCodeType(),
            start: $creneau->getDebut(),
            end: $creneau->getFin(),
            residualCapacity: max(0, $creneau->getCapacite() - $occupees),
        );
    }
}
