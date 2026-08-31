<?php

declare(strict_types=1);

namespace App\Tests\Reservation\Api;

use App\Reservation\DataFixtures\ReservationFixtures;
use App\Reservation\Entity\Creneau;
use App\Tests\Reservation\ReservationApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * UNE OCCURRENCE DE RÉCURRENCE EN CONFLIT N'EST PLUS PERDUE EN SILENCE (RG-M5-11).
 *
 * ── CE QUE CE FICHIER REMPLACE ─────────────────────────────────────────────────────────────────
 *
 * `CreerCreneauProcessor` écrivait `continue` sur une occurrence en conflit. Un cours hebdomadaire
 * de douze séances dont trois tombent sur un court déjà pris en créait **neuf**, l'API rendait 200,
 * et rien ne disait que trois manquaient. L'exploitant l'apprenait quand un client ne pouvait pas
 * réserver une date — ou ne l'apprenait pas.
 *
 * ⚠ TOUT CE QU'IL FALLAIT POUR FAIRE MIEUX EXISTAIT, ET RIEN N'Y MENAIT :
 * `Creneau::$enAttenteArbitrage` n'avait aucun appelant de son setter en production,
 * `RecurrenceReportHandler` était appelé par quatre tests et zéro code de production, et
 * `ArbitrerConflitRecurrenceProcessor` résolvait un état que rien ne produisait.
 *
 * ── LA DÉCISION, ET CE QU'ELLE EXCLUT ──────────────────────────────────────────────────────────
 *
 * Maxime, 31/08, entre quatre options : **« ne jamais déplacer tout seul »**. L'occurrence est donc
 * créée, marquée, et non réservable ; un humain tranche. Le report automatique
 * (`RecurrenceReportHandler`) reste débranché, volontairement — un cours qui change de court sans
 * que personne ne l'ait validé remplace un problème constaté par un problème invisible.
 */
final class OccurrenceEnConflitTest extends ReservationApiTestCase
{
    /**
     * L'occurrence qui chevauche est créée, marquée, et le reste de la récurrence est intact.
     *
     * ⚠ LE TÉMOIN EST LA PREMIÈRE ASSERTION, ET IL PORTE TOUT : la séance du 21/09 doit EXISTER.
     * Avant ce lot elle n'existait pas — et c'est un test qui n'aurait rien trouvé d'anormal, parce
     * qu'une absence ne lève rien.
     */
    public function testUneOccurrenceEnConflitEstCreeeEtMarquee(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/'.$this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        // L'occupation qui provoquera le conflit : un créneau isolé le lundi 21/09.
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-21T10:00:00+00:00',
                'fin' => '2026-09-21T11:00:00+00:00',
                'capacite' => 4,
            ],
        ]);
        self::assertResponseIsSuccessful();

        // La récurrence hebdomadaire du 14/09 au 28/09 : sa deuxième occurrence tombe le 21/09.
        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-14T10:00:00+00:00',
                'fin' => '2026-09-14T11:00:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-09-28', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $enConflit = $this->creneauDu('2026-09-21T10:00:00+00:00', true);

        self::assertInstanceOf(
            Creneau::class,
            $enConflit,
            'L’occurrence en conflit du 21/09 n’a pas été créée : elle est perdue en silence, et '
            .'l’exploitant croit avoir un cours de trois séances alors qu’il en a deux.',
        );
        self::assertTrue(
            $enConflit->isEnAttenteArbitrage(),
            'L’occurrence en conflit est créée mais NON marquée : elle chevauche une autre '
            .'occupation de la même ressource et se réserverait comme une séance ordinaire.',
        );

        // ⚠ TÉMOIN NÉGATIF : les occurrences sans conflit ne doivent PAS être marquées. Sans lui,
        // un code qui marquerait tout rendrait l’assertion précédente verte.
        $sansConflit = $this->creneauDu('2026-09-28T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $sansConflit, 'témoin : la troisième occurrence doit exister.');
        self::assertFalse(
            $sansConflit->isEnAttenteArbitrage(),
            'témoin : une occurrence sans conflit est marquée en attente d’arbitrage. Si tout est '
            .'marqué, l’assertion précédente ne prouve rien.',
        );
    }

    /**
     * ⚠ SANS CE REFUS, LE LOT ENTIER SERAIT UNE RÉGRESSION.
     *
     * Les occurrences créées chevauchent, par construction, une autre occupation de la même
     * ressource. Réservables, elles donneraient le même court à deux personnes à la même heure —
     * exactement ce que RG-M5-03 interdit à la création, obtenu par la porte de derrière.
     */
    public function testUnCreneauEnAttenteDArbitrageRefuseLaReservation(): void
    {
        // ⚠ L ADMINISTRATEUR, PAS LE GESTIONNAIRE. Reserver exige d ouvrir une session de caisse, et
        // le gestionnaire de creneaux n a pas ce droit — la premiere version de ce test echouait sur
        // « Access Denied » a l ouverture de session, pas sur ce qu elle pretendait mesurer.
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/'.$this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => ['ressource' => $terrain, 'debut' => '2026-10-05T10:00:00+00:00', 'fin' => '2026-10-05T11:00:00+00:00', 'capacite' => 4],
        ]);
        self::assertResponseIsSuccessful();
        $libre = $client->getResponse()->toArray();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-09-28T10:00:00+00:00',
                'fin' => '2026-09-28T11:00:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-10-05', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $marque = $this->creneauDu('2026-10-05T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $marque);
        self::assertTrue($marque->isEnAttenteArbitrage(), 'témoin : le créneau doit être marqué avant qu’on teste le refus.');

        // ── TÉMOIN POSITIF D'ABORD : le créneau libre, lui, se réserve. ─────────────────────────
        //
        // Sans lui, une réservation cassée pour n'importe quelle raison — session absente,
        // bénéficiaire introuvable, droit manquant — rendrait le refus ci-dessous vert.
        $session = $this->ouvrirSession($client, $entete);
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/'.$libre['id'],
                'organisateur' => '/api/beneficiaires/'.$this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/'.$session['id'],
            ],
        ]);
        self::assertResponseIsSuccessful('témoin : un créneau ordinaire doit se réserver, sinon le refus suivant ne prouve rien.');

        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/'.$marque->getId(),
                'organisateur' => '/api/beneficiaires/'.$this->idBeneficiairePayeur(),
                'session' => '/api/session_caisses/'.$session['id'],
            ],
        ]);
        self::assertResponseStatusCodeSame(
            409,
            'Un créneau en attente d’arbitrage se réserve : deux personnes peuvent recevoir la même '
            .'ressource à la même heure.',
        );
    }

    /**
     * L'arbitrage rend la séance réservable — sinon le marquage est un cul-de-sac.
     *
     * Une séance marquée qu'on ne peut pas débloquer serait pire que la perdre : l'exploitant la
     * voit, ne peut rien en faire, et n'a même plus la possibilité de la recréer ailleurs.
     */
    public function testArbitrerRendLaSeanceReservable(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/'.$this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => ['ressource' => $terrain, 'debut' => '2026-11-16T10:00:00+00:00', 'fin' => '2026-11-16T11:00:00+00:00', 'capacite' => 4],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-11-09T10:00:00+00:00',
                'fin' => '2026-11-09T11:00:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-11-16', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $marque = $this->creneauDu('2026-11-16T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $marque);
        self::assertTrue($marque->isEnAttenteArbitrage(), 'témoin : sans marquage, arbitrer ne prouve rien.');

        // Confirmer la séance telle quelle : corps vide, aucune ressource proposée.
        $client->request('POST', '/api/reservation/creneaux/'.$marque->getId().'/arbitrer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $apres = $this->creneauDu('2026-11-16T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $apres);
        self::assertFalse(
            $apres->isEnAttenteArbitrage(),
            'L’arbitrage ne lève pas le drapeau : la séance reste bloquée pour toujours, et personne '
            .'ne peut ni la réserver ni la récupérer.',
        );
    }

    /**
     * ⚠ ARBITRER VERS UNE RESSOURCE OCCUPÉE DOIT ÊTRE REFUSÉ.
     *
     * Sans ce refus, l'arbitrage **déplace** le conflit au lieu de le résoudre : le créneau quitte
     * un chevauchement pour un autre, le drapeau tombe, et l'écran annonce « arbitré ». Deux
     * personnes recevraient la même ressource à la même heure — ce que RG-M5-03 interdit à la
     * création, obtenu par la porte de derrière.
     *
     * Le trou était inatteignable tant que rien ne produisait cet état. Il devient atteignable dans
     * le même lot que l'écran qui arbitre, et c'est pour ça qu'il est fermé ici plutôt que plus tard.
     */
    public function testArbitrerVersUneRessourceOccupeeEstRefuse(): void
    {
        [$client, $entete] = $this->gestionnaireSurA();
        $client->disableReboot();
        $terrain = '/api/reservation_ressources/'.$this->idRessource(ReservationFixtures::RESSOURCE_TERRAIN_LIBELLE);

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => ['ressource' => $terrain, 'debut' => '2026-12-14T10:00:00+00:00', 'fin' => '2026-12-14T11:00:00+00:00', 'capacite' => 4],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/reservation/creneaux', $entete + [
            'json' => [
                'ressource' => $terrain,
                'debut' => '2026-12-07T10:00:00+00:00',
                'fin' => '2026-12-07T11:00:00+00:00',
                'capacite' => 4,
                'recurrence' => ['motif' => 'hebdomadaire', 'finRecurrence' => '2026-12-14', 'joursSemaine' => [1]],
            ],
        ]);
        self::assertResponseIsSuccessful();

        $marque = $this->creneauDu('2026-12-14T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $marque);
        self::assertTrue($marque->isEnAttenteArbitrage(), 'témoin : sans marquage, le refus ne prouve rien.');

        // On propose la ressource DÉJÀ occupée — celle du créneau isolé, c'est-à-dire la même.
        $client->request('POST', '/api/reservation/creneaux/'.$marque->getId().'/arbitrer', $entete + [
            'json' => ['ressource' => $terrain],
        ]);
        self::assertResponseStatusCodeSame(
            409,
            'Arbitrer vers une ressource occupée est accepté : le conflit est déplacé et non résolu, '
            .'et le drapeau tombe en annonçant que tout va bien.',
        );

        $apres = $this->creneauDu('2026-12-14T10:00:00+00:00', true);
        self::assertInstanceOf(Creneau::class, $apres);
        self::assertTrue(
            $apres->isEnAttenteArbitrage(),
            'Le refus a quand même levé le drapeau : le créneau se croit arbitré alors que rien n’a '
            .'changé, et il redevient réservable en plein chevauchement.',
        );
    }

    // ── Outillage ────────────────────────────────────────────────────────────────────────────────

    /**
     * Le créneau qui commence à cet instant, sur le terrain des fixtures.
     *
     * ⚠ RELU DEPUIS LA BASE, ET L'`EntityManager` VIDÉ D'ABORD. Le client de test réinitialise ses
     * services entre deux requêtes, ce qui détache les entités ; une lecture sans `clear()` peut
     * rendre un objet d'avant la requête qu'on cherche justement à observer.
     */
    private function creneauDu(string $instant, bool $vider = false): ?Creneau
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        if ($vider) {
            $em->clear();
        }

        /** @var list<Creneau> $tous */
        $tous = $em->getRepository(Creneau::class)->findBy(['debut' => new \DateTimeImmutable($instant)]);

        // Le créneau isolé posé en premier porte le même instant que l'occurrence en conflit : on
        // veut CELLE de la récurrence, donc celui qui porte une récurrence.
        foreach ($tous as $creneau) {
            if ($creneau->getRecurrence() !== null) {
                return $creneau;
            }
        }

        return $tous[0] ?? null;
    }
}
