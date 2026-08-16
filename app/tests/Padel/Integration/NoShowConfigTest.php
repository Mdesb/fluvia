<?php

declare(strict_types=1);

namespace App\Tests\Padel\Integration;

use App\Reservation\Entity\FacturationNoShow;
use App\Reservation\Entity\Reservation;
use App\Tests\Padel\PadelApiTestCase;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Configuration no-show / annulation tardive padel (RG-PADEL-06, décision actée, CA-13). Le padel se
 * limite à **fournir les valeurs de paramètres** (`RegleAnnulation` scopée `terrain_padel`, délai franc
 * 24h — fixture `PadelFixtures`) ; le déclenchement/la facturation sont entièrement génériques au
 * socle Réservation, non redéfinis ici.
 */
final class NoShowConfigTest extends PadelApiTestCase
{
    public function testCa13AnnulationHorsDelaiFrancFacturee(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idReservation = $this->reserverTerrain($client, $entete);

        // Annulation hors délai franc (24h, fixture) : le créneau est dans ~6 jours mais la date
        // limite d'annulation est calculée à `debut - 1440 min`. On simule une annulation tardive en
        // reculant directement `dateLimiteAnnulation` en base (le créneau reste dans le futur).
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();
        $reservation = $em->getRepository(Reservation::class)->find($idReservation);
        self::assertNotNull($reservation->getDateLimiteAnnulation(), 'RG-PADEL-06 : une RegleAnnulation dédiée terrain_padel a bien été résolue.');
        $reservation->setDateLimiteAnnulation(new \DateTimeImmutable('-1 hour'));
        $em->flush();

        $client->request('POST', '/api/reservation/reservations/' . $idReservation . '/annuler', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $donnees = $client->getResponse()->toArray();
        self::assertSame('annulee_tardive_facturee', $donnees['statut'] ?? null, 'CA-13 : annulation tardive facturée (délai franc dépassé).');

        $facturation = $em->getRepository(FacturationNoShow::class)->findOneBy(['reservation' => $idReservation]);
        self::assertNotNull($facturation, 'CA-13 : une facturation est déclenchée par le socle générique (RegleAnnulation terrain_padel).');
        self::assertSame('15.00', $facturation->getMontant());
    }

    /**
     * @param array<string, mixed> $entete
     */
    private function reserverTerrain(object $client, array $entete): string
    {
        $idTerrain = $this->idTerrain();
        $debut = (new \DateTimeImmutable('next monday'))->setTime(19, 0);
        $client->request('POST', '/api/padel/terrains/' . $idTerrain . '/reservations', $entete + [
            'json' => [
                'debut' => $debut->format(DATE_ATOM),
                'dureeMinutes' => 90,
                'organisateur' => '/api/beneficiaires/' . $this->idJoueur(1),
            ],
        ]);
        self::assertResponseIsSuccessful();

        return basename((string) $client->getResponse()->toArray()['reservation']);
    }
}
