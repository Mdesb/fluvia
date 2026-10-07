<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\DataFixtures\SocleFixtures;
use App\Reservation\Command\ExpireReservationConfirmationsCommand;
use App\Reservation\Entity\RegleAnnulation;
use App\Reservation\Entity\Reservation;
use App\Padel\Entity\TerrainPadel;
use App\Reservation\Enum\ConfirmationExpiry;
use App\Tests\Padel\PadelApiTestCase;
use App\Tests\Reservation\ConcurrentSlotWriter;
use Doctrine\ORM\EntityManagerInterface;

/**
 * CE QU'IL ADVIENT D'UNE RÉSERVATION QUE PERSONNE N'A CONFIRMÉE — R15 (a), seconde moitié.
 *
 * Maxime : « ces décisions sont des décisions **métier**, il faut laisser le choix à l'exploitant ».
 * Trois comportements, et ce fichier vérifie que les trois font ce qu'ils annoncent — y compris
 * celui qui ne fait rien, qui est le plus facile à croire sur parole.
 */
final class ExpirationConfirmationTest extends PadelApiTestCase
{
    use ConcurrentSlotWriter;

    /**
     * ⚠ LA JAUGE GLOBALE SE REND À L'EXPIRATION — et les deux moitiés se corrigent ensemble.
     *
     * Le chemin padel ne comptait pas sa place sur `Ressource.occupationCourante` ; l'expiration
     * n'avait donc rien à rendre, et l'absence d'un geste compensait l'absence de l'autre. Ce test
     * tient les deux : la réservation compte à la création, l'expiration la rend.
     */
    public function testUneExpirationRendLaPlaceSurLaJaugeGlobale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, ConfirmationExpiry::Release);

        $terrain = $this->entite(TerrainPadel::class, []);
        \assert($terrain instanceof TerrainPadel);
        $ressource = $terrain->getRessource();
        self::assertNotNull($ressource, 'Terrain padel sans ressource socle (fixture).');
        $idRessource = (string) $ressource->getId();
        $avant = $this->occupationInDatabase($idRessource);

        $this->reserverDansLeDelai($client, $entete);
        self::assertSame($avant + 1, $this->occupationInDatabase($idRessource), 'la réservation tient une place sur la jauge globale');

        $compte = $this->expirer();
        self::assertSame(1, $compte['liberees'], 'témoin : l\'expiration a bien traité cette réservation');
        self::assertSame($avant, $this->occupationInDatabase($idRessource), 'et l\'expiration rend exactement ce qu\'elle avait pris');
    }

    /** `release` : le créneau repart à la vente. */
    public function testLiberer(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, ConfirmationExpiry::Release);

        $id = $this->reserverDansLeDelai($client, $entete);
        $compte = $this->expirer();

        self::assertSame(1, $compte['liberees'], 'la réservation non confirmée doit être libérée');
        self::assertSame('annulee_libre', $this->statutDe($id), 'et son statut le dit');
    }

    /**
     * ⚠ `keep` : RIEN NE BOUGE — et c'est le cas le plus facile à croire sans le vérifier.
     *
     * Un comportement qui ne fait rien passe pour correct dans n'importe quelle implémentation,
     * y compris une qui ne lit jamais le paramètre. On vérifie donc les DEUX faits : le compteur
     * l'a vue, et le statut n'a pas changé. Le premier sans le second laisserait passer un « on
     * n'a rien traité du tout ».
     */
    public function testGarder(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, ConfirmationExpiry::Keep);

        $id = $this->reserverDansLeDelai($client, $entete);
        $compte = $this->expirer();

        self::assertSame(1, $compte['gardees'], 'elle a bien été VUE — sinon le test ne mesure rien');
        self::assertSame(0, $compte['liberees'], 'et surtout pas libérée');
        self::assertSame('a_confirmer', $this->statutDe($id), 'son statut ne bouge pas');
    }

    /**
     * ⚠ `release_and_charge` : LIBÉRÉE, ET COMPTÉE À PART parce que la facturation n'est pas
     * branchée. Un décompte visible vaut mieux qu'un silence qui ressemble à un succès.
     */
    public function testLibererEtFacturerLibereEtSignaleCeQuiManque(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, ConfirmationExpiry::ReleaseAndCharge);

        $id = $this->reserverDansLeDelai($client, $entete);
        $compte = $this->expirer();

        self::assertSame(1, $compte['liberees'], 'la moitié sûre est faite : le créneau repart');
        self::assertSame(1, $compte['a_facturer'], 'et ce qui manque est compté, pas tu');
        self::assertSame('annulee_libre', $this->statutDe($id));
    }

    /**
     * ⚠ AUCUN COMPORTEMENT DÉCLARÉ : ON NE DEVINE PAS.
     *
     * Un exploitant peut poser un délai sans choisir ce qui se passe à l'échéance. Libérer par
     * défaut annulerait des réservations que personne n'a demandé d'annuler. On les compte à part.
     */
    public function testSansComportementDeclareRienNEstDetruit(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, null);

        $id = $this->reserverDansLeDelai($client, $entete);
        $compte = $this->expirer();

        self::assertSame(1, $compte['sans_regle'], 'comptée à part, pour que le trou de paramétrage se voie');
        self::assertSame(0, $compte['liberees'], 'et RIEN n’est détruit sur une absence de choix');
        self::assertSame('a_confirmer', $this->statutDe($id));
    }

    /** ⚠ Une réservation CONFIRMÉE n'est jamais touchée, même si son échéance est passée. */
    public function testUneReservationConfirmeeNEstJamaisTouchee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->poserRegle(1440, ConfirmationExpiry::Release);

        $id = $this->reserverDansLeDelai($client, $entete);
        $client->request('POST', '/api/reservation/reservations/' . $id . '/confirmer', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();

        $compte = $this->expirer();

        self::assertSame(0, $compte['liberees'], 'confirmée à temps : elle ne doit pas être libérée');
        self::assertSame('confirmee', $this->statutDe($id));
    }

    /**
     * Réserve un créneau proche, dont l'échéance de confirmation est donc immédiate.
     *
     * @param array<string, mixed> $entete
     */
    private function reserverDansLeDelai(object $client, array $entete): string
    {
        $debut = (new \DateTimeImmutable())->modify('+3 hours');
        $client->request('POST', '/api/padel/terrains/' . $this->idTerrain() . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful();

        return basename((string) $client->getResponse()->toArray()['reservation']);
    }

    /**
     * Lance l'expiration une minute dans le futur, pour que l'échéance immédiate soit dépassée.
     *
     * @return array{liberees: int, gardees: int, a_facturer: int, sans_regle: int}
     */
    private function expirer(): array
    {
        /** @var ExpireReservationConfirmationsCommand $commande */
        $commande = static::getContainer()->get(ExpireReservationConfirmationsCommand::class);

        return $commande->expirer((new \DateTimeImmutable())->modify('+1 minute'));
    }

    private function statutDe(string $id): string
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $em->clear();
        $reservation = $em->getRepository(Reservation::class)->find($id);
        self::assertNotNull($reservation, 'témoin : la réservation existe');

        return $reservation->getStatut()->value;
    }

    /**
     * Configure les règles de l'établissement A.
     *
     * ⚠ On modifie les règles EXISTANTES : `ResolveurRegleAnnulation` fait un `findOneBy`, et les
     * fixtures en posent déjà. Une règle de plus ne serait jamais résolue.
     */
    private function poserRegle(int $minutes, ?ConfirmationExpiry $expiry): void
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $etablissement = $em->getRepository(\App\Organisation\Entity\Etablissement::class)
            ->find($this->idEtablissement(SocleFixtures::ETAB_A_NOM));
        self::assertNotNull($etablissement);

        $regles = $em->getRepository(RegleAnnulation::class)->findBy(['etablissement' => $etablissement]);
        self::assertNotEmpty($regles, 'témoin : au moins une règle existe, sinon ce test ne mesure rien');

        foreach ($regles as $regle) {
            $regle->setConfirmationDelayMinutes($minutes)->setConfirmationExpiry($expiry);
        }
        $em->flush();
    }
}
