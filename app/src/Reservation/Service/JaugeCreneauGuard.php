<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\StatutReservation;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Jauge d'un Créneau (RG-M5-01) : les unités occupées par les réservations qui tiennent une place
 * (confirmée/honorée) ne peuvent dépasser sa capacité (CA-3/CA-4).
 *
 * **ACT-1 / D16 point 1 — le décompte est une somme de quantités, plus un comptage de lignes.**
 * Une table de huit consomme huit couverts sur les soixante d'un service, pas un. Les réservations
 * antérieures à ce lot portent `quantity = 1` (défaut de colonne), donc la somme redonne exactement
 * l'ancien comptage : la bascule est neutre sur l'existant.
 *
 * **ACT-1 point 3 / D33 — la jauge compte aussi les réservations qui consomment ce créneau sans le
 * viser.** C'est ce qui rend exprimable « soixante couverts sur le service de 20 h » : le service
 * est un `Creneau` posé sur la ressource mère, et une table réservée dessous le consomme.
 */
final class JaugeCreneauGuard
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function placesOccupees(Creneau $creneau): int
    {
        return (int) $this->em->getRepository(Reservation::class)->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.quantity), 0)')
            // ACT-1 point 3 / D33 — occupent ce créneau toutes les réservations qui le CONSOMMENT :
            // celles qui le visent (le visé est toujours dans `consumedSlots`, invariant tenu par
            // `Reservation::setCreneau()`) et celles qui le consomment sans le viser — une table
            // réservée à 20 h consomme le service du soir de la salle. Une jointure et pas un `OR` :
            // `cs.id` étant fixé, chaque réservation ne produit qu'une ligne, donc la somme ne
            // double personne.
            ->join('r.consumedSlots', 'cs')
            ->andWhere('cs.id = :creneau')
            ->setParameter('creneau', $creneau->getId(), 'uuid')
            ->andWhere('r.statut IN (:statuts)')
            ->setParameter('statuts', [StatutReservation::Confirmee->value, StatutReservation::Honoree->value])
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function estComplet(Creneau $creneau): bool
    {
        return $this->placesRestantes($creneau) <= 0;
    }

    /**
     * ACT-1 — « reste-t-il de la place **pour cette demande-là** », la question que `estComplet()`
     * ne pose pas. Un créneau à trois places libres n'est pas complet, et refuse pourtant une table
     * de huit : tant qu'une réservation valait une place, les deux questions se confondaient.
     */
    public function peutAccueillir(Creneau $creneau, int $quantite): bool
    {
        return $this->placesRestantes($creneau) >= $quantite;
    }

    public function placesRestantes(Creneau $creneau): int
    {
        return max(0, $creneau->getCapacite() - $this->placesOccupees($creneau));
    }
}
