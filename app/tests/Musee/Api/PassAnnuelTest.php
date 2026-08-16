<?php

declare(strict_types=1);

namespace App\Tests\Musee\Api;

use App\Musee\Entity\PassAnnuel;
use App\Reservation\Entity\Reservation;
use App\Reservation\Enum\ModeDecompteReservation;
use App\Tests\Musee\MuseeApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Pass annuel « Amis du musée » (US-MUSEE-10, RG-MUS-05, CA-10) : accès illimité à la collection
 * permanente sans nouveau paiement, mais soumis au choix d'un créneau pour toute exposition à jauge
 * — aucune exception au parcours de créneau générique (module socle Réservation, réutilisé).
 */
final class PassAnnuelTest extends MuseeApiTestCase
{
    public function testCa10PassValideEtAccesCollectionPermanenteSansNouveauPaiement(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $pass = $this->entite(PassAnnuel::class, []);

        $client->request('GET', '/api/musee_pass_annuels/' . (string) $pass->getId(), $entete);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertContains('coupe_file', $donnees['avantages']);
        self::assertNotEmpty($donnees['echeance']);

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $passEntite = $em->getRepository(PassAnnuel::class)->find($pass->getId());
        self::assertInstanceOf(PassAnnuel::class, $passEntite);
        self::assertTrue($passEntite->estValide(new \DateTimeImmutable()), 'CA-10 : pass valide, accès collection permanente sans action supplémentaire (aucune Reservation nécessaire).');
    }

    public function testCa10ExpositionAJaugeExigeToujoursUnCreneauMemePourLePorteurDuPass(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $pass = $this->entite(PassAnnuel::class, []);
        $idCreneau = $this->idCreneauApresMidi();

        // Le porteur du pass réserve, à tarif nul, le créneau obligatoire d'une expo à jauge (RG-MUS-01,
        // RG-MUS-05) — même parcours générique que tout client (aucune exception applicative).
        $client->request('POST', '/api/reservation/reservations', $entete + [
            'json' => [
                'creneau' => '/api/reservation_creneaus/' . $idCreneau,
                'organisateur' => '/api/beneficiaires/' . (string) $pass->getAdherent()?->getId(),
            ],
        ]);
        self::assertResponseIsSuccessful();
        $reservation = $client->getResponse()->toArray();

        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservationEntite = $em->getRepository(Reservation::class)->find(basename((string) $reservation['@id']));
        self::assertInstanceOf(Reservation::class, $reservationEntite);
        self::assertSame(ModeDecompteReservation::Gratuit, $reservationEntite->getModeDecompte(), 'CA-10 : tarif nul pour le porteur, mais décrément du quota du créneau identique à un billet payant.');
        self::assertSame('0.00', $reservationEntite->getMontantDu());
    }
}
