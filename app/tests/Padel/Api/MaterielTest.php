<?php

declare(strict_types=1);

namespace App\Tests\Padel\Api;

use App\Tests\Padel\PadelApiTestCase;
use Symfony\Component\Uid\Uuid;

/** Location de matériel (US-PADEL-08, RG-PADEL-04, CA-9). */
final class MaterielTest extends PadelApiTestCase
{
    public function testCa9LocationRattacheeCautionEtStatutRetour(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();

        $idReservation = $this->reserverTerrain($client, $entete);

        $client->request('POST', '/api/padel/locations', $entete + [
            'json' => [
                'reservation' => '/api/reservations/' . $idReservation,
                'article' => (string) Uuid::v4(),
                'quantite' => 2,
                'caution' => '20.00',
            ],
        ]);
        self::assertResponseIsSuccessful();
        $location = $client->getResponse()->toArray();
        self::assertSame('en_cours', $location['statutRetour'] ?? null, 'CA-9 : suivi initial du statut de retour.');
        $idLocation = $location['id'];

        // Grille de retenue paramétrée pour « raquette perdue » (§4.7).
        $etablissement = $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM);
        $client->request('POST', '/api/padel_grille_retenue_materiels', $entete + [
            'json' => ['etablissement' => '/api/etablissements/' . $etablissement, 'typeArticle' => 'raquette', 'motif' => 'perdu', 'montantRetenue' => '20.00'],
        ]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/padel/locations/' . $idLocation . '/retour', $entete + [
            'json' => ['statutRetour' => 'non_rendu', 'typeArticle' => 'raquette', 'motif' => 'perdu'],
        ]);
        self::assertResponseIsSuccessful();
        $retour = $client->getResponse()->toArray();
        self::assertSame('non_rendu', $retour['statutRetour'] ?? null, 'CA-9 : statut de retour suivi.');

        $client->request('GET', '/api/padel_caution_materiels', $entete);
        self::assertResponseIsSuccessful();
        $cautions = $client->getResponse()->toArray()['member'];
        self::assertNotEmpty($cautions);
        self::assertSame('retenue', $cautions[0]['statut'] ?? null, 'CA-9 : caution retenue appliquée si non rendu.');
        self::assertSame('20.00', $cautions[0]['montantRetenu'] ?? null);
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
