<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Unit;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Reservation\Entity\Reservation;
use App\Reservation\Entity\Ressource;
use App\Reservation\Enum\StatutReservation;
use App\Reservation\Service\JaugeCreneauGuard;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * **La jauge en lot rend exactement ce que la règle dit** — comparée à un oracle, pas à elle-même.
 *
 * `placesOccupees()` délègue désormais à `placesOccupeesPour()`. Comparer les deux reviendrait donc à
 * comparer une fonction à elle-même : un contrôle qui **a l'air** de vérifier quelque chose, ce que
 * `claude-A` a reconnu en retirant sa propre demande.
 *
 * **Ce fichier compare la jauge à un oracle écrit depuis la règle**, jamais depuis le code de
 * production. La distinction est le seul point qui fait tenir cette forme : ré-exprimer la requête SQL
 * en PHP transcrirait **aussi son défaut**, les deux tomberaient d'accord, et le test **confirmerait
 * l'erreur au lieu de l'attraper**. L'oracle ci-dessous a été écrit à partir de D33/ACT-1 —
 * « occupent un créneau les réservations qui le **consomment**, et la jauge est une somme de
 * quantités » — sans ouvrir `JaugeCreneauGuard`.
 *
 * **Un oracle n'est pas une seconde implémentation au sens où le dépôt l'interdit** (`claude-A`) :
 * personne ne le croit, sa seule sortie est une comparaison, sa divergence **est** le signal, et aucun
 * appelant ne peut l'utiliser par erreur. C'est la seule façon de tester un calcul dont on ne peut pas
 * écrire le résultat à la main.
 *
 * **Et il n'est pas théorique.** La première version de la jauge en lot passait un `IN (:liste)` en
 * DQL sur des `Uuid` — la forme que D58 interdit, sous un commentaire qui la décrivait mot pour mot.
 * Elle rendait **zéro partout**, donc « tout est libre » sur un calendrier complet. Cet oracle
 * l'attrape.
 */
final class JaugeLotIdentiqueTest extends ReservationApiTestCase
{
    /** Le lot et l'oracle disent la même chose, créneau par créneau, sur une consommation en cascade. */
    public function testLeLotDitCeQueLaRegleDit(): void
    {
        $creneaux = $this->decorEnCascade();
        $jauge = static::getContainer()->get(JaugeCreneauGuard::class);
        self::assertInstanceOf(JaugeCreneauGuard::class, $jauge);

        $enLot = $jauge->placesOccupeesPour($creneaux);

        foreach ($creneaux as $creneau) {
            $cle = (string) $creneau->getId();
            self::assertSame(
                $this->oracle($creneau),
                $enLot[$cle] ?? null,
                sprintf('Créneau %s : la jauge ne dit pas ce que la règle dit.', $creneau->getDebut()->format('c')),
            );
        }
    }

    /**
     * **Le cas qui a réellement échoué** : la jauge ne doit pas rendre zéro partout.
     *
     * Une assertion d'égalité seule serait vraie si l'oracle et la jauge se trompaient ensemble — par
     * exemple si le décor était vide. Celle-ci exige qu'**au moins un créneau soit occupé**, donc que
     * le test ait quelque chose à comparer.
     */
    public function testLaJaugeNeRendPasZeroPartout(): void
    {
        $creneaux = $this->decorEnCascade();
        $jauge = static::getContainer()->get(JaugeCreneauGuard::class);
        self::assertInstanceOf(JaugeCreneauGuard::class, $jauge);

        self::assertGreaterThan(0, array_sum($jauge->placesOccupeesPour($creneaux)));
    }

    /** Un créneau sans réservation vaut **zéro**, jamais `null` : « libre » et « inconnu » diffèrent. */
    public function testUnCreneauVideVautZeroEtNonNull(): void
    {
        $em = $this->em();
        $ressource = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE]);
        $vide = $this->creneau($ressource, '+40 days 09:00', '+40 days 10:00', 10);
        $em->flush();

        $jauge = static::getContainer()->get(JaugeCreneauGuard::class);
        self::assertInstanceOf(JaugeCreneauGuard::class, $jauge);

        $lot = $jauge->placesOccupeesPour([$vide]);
        self::assertArrayHasKey((string) $vide->getId(), $lot, 'Un créneau absent du résultat se lit « inconnu ».');
        self::assertSame(0, $lot[(string) $vide->getId()]);
    }

    /** La liste vide ne casse rien et n'interroge rien. */
    public function testUneListeVideRendUnTableauVide(): void
    {
        $jauge = static::getContainer()->get(JaugeCreneauGuard::class);
        self::assertInstanceOf(JaugeCreneauGuard::class, $jauge);

        self::assertSame([], $jauge->placesOccupeesPour([]));
    }

    /**
     * **L'oracle — écrit depuis D33/ACT-1, pas depuis `JaugeCreneauGuard`.**
     *
     * La règle, en toutes lettres : occupent un créneau les réservations qui le **consomment** — celles
     * qui le visent comme celles qui le consomment sans le viser, une table réservée à 20 h consommant
     * le service du soir de la salle. Et le décompte est une **somme de quantités**, pas un comptage de
     * lignes : une table de huit prend huit couverts sur soixante. Seules comptent les réservations qui
     * tiennent réellement une place — confirmée et honorée.
     *
     * Volontairement lent et sans SQL : sa justesse doit se lire d'un coup d'œil, sinon on a deux
     * choses à déboguer au lieu d'une.
     */
    private function oracle(Creneau $creneau): int
    {
        $occupees = 0;
        foreach ($this->em()->getRepository(Reservation::class)->findAll() as $reservation) {
            if (!\in_array($reservation->getStatut(), [StatutReservation::Confirmee, StatutReservation::Honoree], true)) {
                continue;
            }
            foreach ($reservation->getConsumedSlots() as $consomme) {
                if ($consomme->getId()->equals($creneau->getId())) {
                    $occupees += $reservation->getQuantity();

                    break;
                }
            }
        }

        return $occupees;
    }

    /**
     * Un service posé sur la ressource mère, deux créneaux d'enfants qui le consomment, et des
     * quantités différentes — le décor où un comptage de lignes donnerait un autre résultat qu'une
     * somme de quantités, et où un filtre « réservations visant ce créneau » raterait le service.
     *
     * @return list<Creneau>
     */
    private function decorEnCascade(): array
    {
        $em = $this->em();
        $bassin = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_BASSIN_LIBELLE]);
        $ligne = $this->entite(Ressource::class, ['libelle' => ReservationFixtures::RESSOURCE_LIGNE_1_LIBELLE]);

        $service = $this->creneau($bassin, '+30 days 18:00', '+30 days 22:00', 60);
        $couloir = $this->creneau($ligne, '+30 days 19:00', '+30 days 20:00', 6);
        $ailleurs = $this->creneau($ligne, '+31 days 19:00', '+31 days 20:00', 6);

        // Deux reservations sur le couloir, de quantites differentes : elles consomment le couloir ET
        // le service, puisque le couloir est sous le bassin.
        $this->reservation($couloir, [$service], 4, StatutReservation::Confirmee);
        $this->reservation($couloir, [$service], 2, StatutReservation::Honoree);
        // Une annulee et une en liste d attente : ni l une ni l autre ne tient de place, et la
        // seconde est le piege — elle EXISTE sur le creneau, elle ne l occupe pas.
        $this->reservation($couloir, [$service], 5, StatutReservation::AnnuleeLibre);
        $this->reservation($couloir, [$service], 7, StatutReservation::ListeAttente);
        // Une sur un autre jour, pour verifier que le lot ne melange pas les creneaux.
        $this->reservation($ailleurs, [], 3, StatutReservation::Confirmee);

        $em->flush();
        $em->clear();

        return [
            $this->entite(Creneau::class, ['id' => $service->getId()]),
            $this->entite(Creneau::class, ['id' => $couloir->getId()]),
            $this->entite(Creneau::class, ['id' => $ailleurs->getId()]),
        ];
    }

    private function creneau(Ressource $ressource, string $debut, string $fin, int $capacite): Creneau
    {
        $creneau = (new Creneau())
            ->setRessource($ressource)
            ->setDebut(new \DateTimeImmutable($debut))
            ->setFin(new \DateTimeImmutable($fin))
            ->setCapacite($capacite);
        $creneau->setEtablissement($ressource->getEtablissement());
        $this->em()->persist($creneau);

        return $creneau;
    }

    /** @param list<Creneau> $consommesEnPlus */
    private function reservation(Creneau $vise, array $consommesEnPlus, int $quantite, StatutReservation $statut): Reservation
    {
        $reservation = new Reservation();
        // `setCreneau()` ajoute d'office le créneau visé aux créneaux consommés (D33) : l'oracle
        // n'a donc rien à ajouter pour lui.
        $reservation->setCreneau($vise)
            ->setQuantity($quantite)
            ->setStatut($statut)
            // Obligatoire en base. Le même pour toutes : la jauge ne regarde pas QUI réserve, et un
            // organisateur par réservation ferait croire que ça compte.
            ->setOrganisateur($this->organisateur())
            ->setEtablissement($vise->getEtablissement());
        foreach ($consommesEnPlus as $consomme) {
            $reservation->addConsumedSlot($consomme);
        }
        $this->em()->persist($reservation);

        return $reservation;
    }

    private function organisateur(): \App\Crm\Entity\Beneficiaire
    {
        /** @var \App\Crm\Entity\Beneficiaire $premier */
        $premier = $this->em()->getRepository(\App\Crm\Entity\Beneficiaire::class)->findOneBy([]);
        self::assertNotNull($premier, 'Le jeu de donnees doit fournir au moins un beneficiaire.');

        return $premier;
    }

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }
}
