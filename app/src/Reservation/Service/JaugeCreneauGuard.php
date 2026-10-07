<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
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

        $statuts = [StatutReservation::AConfirmer->value, StatutReservation::Confirmee->value, StatutReservation::Honoree->value];
        // ⚠ LE NOMBRE DE MARQUEURS SE DEDUIT DE LA LISTE, IL NE SE RECOPIE PAS.
        // Cette requete portait `IN (?, ?)` en dur. Ajouter un troisieme statut le 04/09 a fait
        // sauter TOUTES les jauges — 75 tests — avec un message qui ne nomme rien : « number of
        // bound variables does not match number of tokens ».
        //
        // La bonne technique etait deja dans ce fichier, deux lignes plus haut : `$marqueurs` se
        // construit par `array_fill`. La liste des identifiants etait robuste au nombre, celle des
        // statuts ne l'etait pas — meme requete, deux traitements.
        $marqueursStatuts = implode(', ', array_fill(0, \count($statuts), '?'));

        /** @var list<array{creneau: string, occupees: int|string}> $lignes */
        $lignes = $this->em->getConnection()->executeQuery(
            'SELECT LOWER(HEX(cs.creneau_id)) AS creneau, COALESCE(SUM(r.quantity), 0) AS occupees '
            . 'FROM reservation_consumed_slot cs '
            . 'INNER JOIN reservation_reservation r ON r.id = cs.reservation_id '
            . 'WHERE cs.creneau_id IN (' . $marqueurs . ') '
            . 'AND r.statut IN (' . $marqueursStatuts . ') '
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

    /**
     * Sérialise les réservations concurrentes : verrouille, jusqu'à la fin de la transaction en cours,
     * les lignes des créneaux qu'une réservation va consommer et celle de la ressource porteuse de la
     * jauge globale, puis relit leur capacité et leur compteur.
     *
     * **Pourquoi.** Le contrôle de jauge lit l'état validé, et la réservation s'écrit plus loin. Deux
     * demandes simultanées sur la dernière place lisaient toutes deux « une place libre » et passaient
     * toutes deux : la sur-réservation que ce service est censé empêcher. Sous verrou, la seconde attend
     * la validation de la première, et son décompte la voit.
     *
     * **Trois conditions, et l'oubli de chacune rend le verrou inopérant sans rien signaler :**
     *  1. une transaction doit être ouverte — sans elle, le verrou tombe à la fin de l'instruction.
     *     D'où l'exception, plutôt qu'un verrou qui ne verrouille rien ;
     *  2. le verrou doit être la PREMIÈRE lecture de la transaction. En REPEATABLE READ (le défaut de
     *     MariaDB), la première lecture non verrouillante fige l'instantané : faite avant d'attendre,
     *     elle ferait compter l'état d'avant la réservation qu'on vient d'attendre. Les créneaux
     *     consommés se résolvent donc AVANT d'ouvrir la transaction, et les relectures viennent après ;
     *  3. l'ordre est fixe, par identifiant : deux réservations qui consomment les mêmes créneaux les
     *     prennent dans le même ordre, et ne peuvent pas s'attendre en croix.
     *
     * `ConcurrentBookingTest` joue la concurrence avec un second processus ; il échoue si l'une des
     * deux premières conditions manque.
     *
     * @param list<Creneau> $creneaux
     */
    public function verrouiller(array $creneaux, ?Ressource $porteuse): void
    {
        $connexion = $this->em->getConnection();
        if (!$connexion->isTransactionActive()) {
            throw new \LogicException('Verrouiller la jauge exige une transaction ouverte : sans elle, le verrou tombe à la fin de l\'instruction.');
        }

        $hex = [];
        foreach ($creneaux as $creneau) {
            $hex[] = str_replace('-', '', (string) $creneau->getId());
        }
        $hex = array_values(array_unique($hex));
        sort($hex, \SORT_STRING);

        if ($hex !== []) {
            // D58 — une liste d'identifiants s'écrit en SQL avec `UNHEX`, jamais en `IN` DQL.
            $marqueurs = implode(', ', array_fill(0, \count($hex), 'UNHEX(?)'));
            $connexion->executeQuery(
                'SELECT id FROM reservation_creneau WHERE id IN (' . $marqueurs . ') ORDER BY id FOR UPDATE',
                $hex,
            )->fetchAllAssociative();
        }
        if ($porteuse !== null) {
            $connexion->executeQuery(
                'SELECT id FROM reservation_ressource WHERE id = UNHEX(?) FOR UPDATE',
                [str_replace('-', '', (string) $porteuse->getId())],
            )->fetchAllAssociative();
        }

        // Les objets en mémoire ont été chargés avant l'attente : capacité et compteur global se
        // relisent sous verrou, sinon on contrôlerait la jauge avec les chiffres d'avant.
        foreach ($creneaux as $creneau) {
            $this->em->refresh($creneau);
        }
        if ($porteuse !== null) {
            $this->em->refresh($porteuse);
        }
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
