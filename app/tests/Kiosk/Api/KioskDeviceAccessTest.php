<?php

declare(strict_types=1);

namespace App\Tests\Kiosk\Api;

use App\Kiosk\DataFixtures\KioskFixtures;
use App\Kiosk\Entity\Kiosk;
use App\Tests\Kiosk\KioskTestTrait;
use App\Tests\Vente\VenteApiTestCase;

/**
 * L'appareil de borne : appairage à usage unique, code de sortie vérifié par le serveur et verrouillé
 * après cinq échecs, désappairage immédiat. Le verrouillage de l'ÉCRAN ne vaut que si sa clé ne se lit
 * pas dans la page et ne se devine pas.
 */
final class KioskDeviceAccessTest extends VenteApiTestCase
{
    use KioskTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadKioskFixtures();
    }

    public function testAPairingCodeIsSingleUse(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $id = (string) $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId();
        $code = $client->request('POST', '/api/kiosks/' . $id . '/pairing-code', $staff + ['json' => []])->toArray()['code'];
        self::assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $code);

        // Recopié en minuscules et sans tiret, il passe ; une seconde fois, il ne passe plus.
        $client->request('POST', '/api/kiosk/pair', ['json' => ['code' => strtolower(str_replace('-', ' ', $code))]]);
        self::assertResponseStatusCodeSame(201);
        $client->request('POST', '/api/kiosk/pair', ['json' => ['code' => $code]]);
        self::assertResponseStatusCodeSame(422);

        // La base ne garde que des empreintes.
        $kiosk = $this->kioskEm()->getRepository(Kiosk::class)->find($this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId());
        self::assertInstanceOf(Kiosk::class, $kiosk);
        self::assertSame(64, \strlen((string) $kiosk->getDeviceTokenHash()));
        self::assertNull($kiosk->getPairingCodeHash());
    }

    public function testAnExpiredPairingCodeIsRefused(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $id = (string) $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId();
        $code = $client->request('POST', '/api/kiosks/' . $id . '/pairing-code', $staff + ['json' => []])->toArray()['code'];

        $em = $this->kioskEm();
        $kiosk = $this->kiosk(KioskFixtures::KIOSK_A_LABEL);
        $kiosk->setPairingCode($kiosk->getPairingCodeHash(), new \DateTimeImmutable('-1 minute'));
        $em->flush();

        $client->request('POST', '/api/kiosk/pair', ['json' => ['code' => $code]]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testNoPairingWithoutAnExitCode(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $em = $this->kioskEm();
        $kiosk = $this->kiosk(KioskFixtures::KIOSK_A_LABEL);
        $kiosk->setExitCodeHash(null);
        $em->flush();

        $client->request('POST', '/api/kiosks/' . $kiosk->getId() . '/pairing-code', $staff + ['json' => []]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testTheExitCodeIsCheckedByTheServerAndLocksAfterFiveFailures(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => KioskFixtures::EXIT_CODE]);
        self::assertSame(200, $status, 'Le bon code déverrouille.');

        for ($i = 1; $i <= 4; ++$i) {
            [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => '0000']);
            self::assertSame(403, $status);
        }
        [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => '0000']);
        self::assertSame(403, $status);

        // Cinq échecs : même le BON code est refusé pendant le verrou.
        [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => KioskFixtures::EXIT_CODE]);
        self::assertSame(429, $status, 'Après cinq échecs, la porte est fermée quelques minutes.');
    }

    public function testTheExitCodeIsStoredHashedAndCanBeChanged(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        $client->request('PATCH', '/api/kiosks/' . $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId(), [
            'auth_bearer' => $staff['auth_bearer'],
            'headers' => $staff['headers'] + ['Content-Type' => 'application/merge-patch+json'],
            'json' => ['exitCode' => '97531'],
        ]);
        self::assertResponseIsSuccessful();
        $body = (string) $client->getResponse()?->getContent();
        self::assertNotEmpty($body, 'sinon le test ne prouverait rien');
        self::assertStringNotContainsString('97531', $body);

        $hash = (string) $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getExitCodeHash();
        self::assertNotEmpty($hash, 'sinon le test ne prouverait rien');
        self::assertStringNotContainsString('97531', $hash);
        [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => KioskFixtures::EXIT_CODE]);
        self::assertSame(403, $status, 'L\'ancien code ne vaut plus.');
        [$status] = $this->device($client, $token, 'POST', '/unlock', ['code' => '97531']);
        self::assertSame(200, $status);
    }

    public function testUnpairingRevokesTheDeviceAtOnce(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        [$status] = $this->device($client, $token, 'GET', '/config');
        self::assertSame(200, $status);

        $client->request('POST', '/api/kiosks/' . $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getId() . '/unpair', $staff + ['json' => []]);
        self::assertResponseIsSuccessful();

        [$status, $body] = $this->device($client, $token, 'GET', '/config');
        self::assertSame(401, $status);
        self::assertSame('kiosk_not_paired', $body['code']);
    }

    public function testAKioskNeedsItsOwnPointOfSaleOnItsSite(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $pos = $this->kiosk(KioskFixtures::KIOSK_A_LABEL)->getPointOfSale();
        self::assertNotNull($pos);

        // Le point de vente d'une borne existante : refusé, une borne a le sien.
        $client->request('POST', '/api/kiosks', $staff + ['json' => ['label' => 'Seconde borne', 'pointOfSale' => '/api/point_de_ventes/' . $pos->getId()]]);
        self::assertResponseStatusCodeSame(409);

        // Le point de vente de la borne B (autre établissement) : il n'existe pas ici.
        $posB = $this->kiosk(KioskFixtures::KIOSK_B_LABEL)->getPointOfSale();
        self::assertNotNull($posB);
        $client->request('POST', '/api/kiosks', $staff + ['json' => ['label' => 'Borne pirate', 'pointOfSale' => '/api/point_de_ventes/' . $posB->getId()]]);
        self::assertContains($client->getResponse()?->getStatusCode(), [400, 404, 422]);
        self::assertNull($this->kioskEm()->getRepository(Kiosk::class)->findOneBy(['label' => 'Borne pirate']));

        // Le point de vente du guichet principal (il porte une caisse) : refusé, ses ventes iraient dans sa chaîne.
        $client->request('POST', '/api/kiosks', $staff + ['json' => ['label' => 'Borne du guichet', 'pointOfSale' => '/api/point_de_ventes/' . $this->idPointDeVente()]]);
        self::assertResponseStatusCodeSame(422);

        // Témoin : un point de vente neuf, sur ce site, sans caisse ni borne, est accepté.
        $newPos = $client->request('POST', '/api/point_de_ventes', $staff + ['json' => ['libelle' => 'Borne du bassin extérieur', 'etablissement' => '/api/etablissements/' . $this->idEtablissement(\App\DataFixtures\SocleFixtures::ETAB_A_NOM), 'moyensAutorises' => ['cb']]]);
        self::assertResponseStatusCodeSame(201, (string) $newPos->getContent(false));
        $client->request('POST', '/api/kiosks', $staff + ['json' => ['label' => 'Borne du bassin', 'pointOfSale' => '/api/point_de_ventes/' . $newPos->toArray()['id'], 'exitCode' => '1357']]);
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()?->getContent(false));
        self::assertTrue($this->kiosk('Borne du bassin')->hasExitCode());
    }
}
