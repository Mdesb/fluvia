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

    /**
     * Les places occupées d'**un** créneau.
     *
     * Cas particulier à un élément de `placesOccupeesPour()` : un seul calcul, deux chemins d'appel.
     * Un calendrier demande l'occupation de plusieurs centaines de créneaux d'un coup ; garder deux
     * implémentations de la jauge serait la pire divergence possible du dépôt, puisque c'est elle qui
     * décide si une réservation est acceptée.
     */
    public function placesOccupees(Creneau $creneau): int
    {
        return $this->placesOccupeesPour([$creneau])[(string) $creneau->getId()] ?? 0;
    }

    /**
     * Les places occupées de **plusieurs** créneaux, en **une** requête.
     *
     * **Pourquoi cette variante existe.** `placesOccupees()` interroge la base une fois par créneau.
     * Afficher un mois de calendrier sur une ressource, c'est quelques centaines de créneaux — donc
     * quelques centaines de requêtes pour une seule vue. Le défaut ne se voit pas en test, où l'on
     * compte trois créneaux ; il se voit en exploitation, sur la vue qu'on ouvre tous les matins.
     *
     * **Et pourquoi ce n'est pas un second service.** Un écran ne peut pas calculer l'occupation
     * lui-même : elle ne compte pas les réservations qui *visent* le créneau mais celles qui le
     * **consomment** — une table réservée à 20 h consomme le service du soir de la salle (D33) — et
     * `Reservation::$consumedSlots` n'est délibérément pas sérialisé. Le naïf ne diverge donc pas un
     * jour : **il est faux tout de suite**, et il rend un nombre plausible.
     *
     * `JaugeLotIdentiqueTest` compare les deux chemins créneau par créneau. Sans lui, il y aurait deux
     * chemins et une intention ; avec lui, il y a un calcul.
     *
     * @param list<Creneau> $creneaux
     *
     * @return array<string, int> identifiant de créneau => places occupées (0 pour les créneaux vides)
     */
    public function placesOccupeesPour(array $creneaux): array
    {
        $occupees = [];
        $identifiants = [];
        foreach ($creneaux as $creneau) {
            $identifiants[] = $creneau->getId();
            // Un créneau sans réservation n'apparaît pas dans le résultat groupé — il doit quand même
            // valoir zéro, sinon l'appelant lit `null` et affiche « inconnu » là où c'est « libre ».
            $occupees[(string) $creneau->getId()] = 0;
        }

        if ($identifiants === []) {
            return [];
        }

        // ⚠ **PREMIERE VERSION FAUSSE, ET LE COMMENTAIRE QUI L ACCOMPAGNAIT ETAIT JUSTE.** J avais
        // ecrit un `IN (:creneaux)` en DQL sur une liste d identifiants `Uuid`, avec, juste au-dessus,
        // la mise en garde disant que cette forme « ne trouve rien et ne leve pas ». C est exactement
        // ce qui s est produit : la jauge rendait ZERO PARTOUT, donc « tout est libre », et
        // `ReservationQuotaVenteTest` a cesse de refuser une reservation sur un creneau complet.
        //
        // D58 est sans nuance sur ce point : pour une LISTE, aucun type scalaire ne s applique, donc
        // `IN` reste toujours fautif — il faut du SQL avec `UNHEX`, comme `CardRechargeHandler` et
        // `ExplainedGapProvider`. Une regle qu on connait, qu on ecrit en commentaire, et qu on
        // enfreint sur la ligne suivante : le commentaire ne protege pas, seul le test protege.
        $hex = array_map(static fn (string $id): string => str_replace('-', '', $id), array_map('strval', $identifiants));
        $marqueurs = implode(', ', array_fill(0, \count($hex), 'UNHEX(?)'));

        $statuts = [StatutReservation::Confirmee->value, StatutReservation::Honoree->value];

        /** @var list<array{creneau: string, occupees: int|string}> $lignes */
        $lignes = $this->em->getConnection()->executeQuery(
            'SELECT LOWER(HEX(cs.creneau_id)) AS creneau, COALESCE(SUM(r.quantity), 0) AS occupees '
            . 'FROM reservation_consumed_slot cs '
            . 'INNER JOIN reservation_reservation r ON r.id = cs.reservation_id '
            . 'WHERE cs.creneau_id IN (' . $marqueurs . ') '
            . 'AND r.statut IN (?, ?) '
            . 'GROUP BY cs.creneau_id',
            array_merge($hex, $statuts),
        )->fetchAllAssociative();

        // `LOWER(HEX())` rend l identifiant SANS tirets ; les cles du tableau rendu sont, elles, les
        // identifiants canoniques. On remappe explicitement plutot que de laisser l appelant deviner
        // quelle forme il recoit — une cle qui ne correspond a rien se lit « zero place occupee ».
        $parHex = [];
        foreach ($identifiants as $identifiant) {
            $parHex[str_replace('-', '', (string) $identifiant)] = (string) $identifiant;
        }
        foreach ($lignes as $ligne) {
            $cle = $parHex[strtolower((string) $ligne['creneau'])] ?? null;
            if ($cle !== null) {
                $occupees[$cle] = (int) $ligne['occupees'];
            }
        }

        return $occupees;
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
