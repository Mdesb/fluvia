<?php

declare(strict_types=1);

namespace App\Tests\Kiosk\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Acces\Entity\DroitAcces;
use App\Kiosk\DataFixtures\KioskFixtures;
use App\Kiosk\Entity\KioskOrder;
use App\Offre\DataFixtures\OffreFixtures;
use App\Tests\Acces\AccesApiTestCase;
use App\Tests\Kiosk\KioskTestTrait;
use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutTPE;
use Symfony\Component\Uid\Uuid;

/**
 * Recharger sa carte à la borne (#341) : la carte vendue et appairée au guichet est créditée, sans
 * nouveau support — et JAMAIS si le paiement est refusé, ni sur une carte inconnue (vérifiée avant le
 * débit).
 */
final class KioskRechargeTest extends AccesApiTestCase
{
    use KioskTestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadKioskFixtures();
    }

    public function testRechargeCreditsTheSameCardWithoutANewSupport(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        [$cardCode, $rightId] = $this->sellAndPairACardAtTheCounter($client, $staff);
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        $saleId = $this->openRecharge($client, $token, $cardCode);
        [$status, $paid] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame('accepted', $paid['status'], json_encode($paid));
        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertTrue($done['recharge']);
        self::assertSame([], $done['ticket']['supports'], 'Une recharge n\'émet aucun nouveau support (RG-CQ1-05).');

        $this->kioskEm()->clear();
        $right = $this->kioskEm()->getRepository(DroitAcces::class)->find(Uuid::fromString($rightId));
        self::assertInstanceOf(DroitAcces::class, $right);
        self::assertSame(24, $right->getCreditRestant(), '12 à l\'émission + 12 rechargées à la borne.');
    }

    public function testARefusedRechargeCreditsNothing(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        [$cardCode, $rightId] = $this->sellAndPairACardAtTheCounter($client, $staff);
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        $saleId = $this->openRecharge($client, $token, $cardCode);
        $this->forceTerminal(StatutTPE::Refuse);
        [, $paid] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame('refused', $paid['status']);
        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status);

        $this->kioskEm()->clear();
        $right = $this->kioskEm()->getRepository(DroitAcces::class)->find(Uuid::fromString($rightId));
        self::assertInstanceOf(DroitAcces::class, $right);
        self::assertSame(12, $right->getCreditRestant(), 'Un paiement refusé ne crédite rien.');
    }

    public function testAnUnknownCardIsRefusedBeforeAnyPayment(): void
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);
        [, $config] = $this->device($client, $token, 'GET', '/config');
        $offer = $this->offerOf($config, OffreFixtures::PRODUIT_CARTE, OffreFixtures::TARIF_PLEIN);

        [$status] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $offer, 'quantity' => 1]], 'cardCode' => 'CARTE-INCONNUE']);
        self::assertSame(404, $status);
        self::assertSame(0, $this->kioskEm()->getRepository(KioskOrder::class)->count([]), 'Aucune commande n\'est ouverte : rien à payer.');
    }

    /**
     * @param array<string, mixed> $staff
     *
     * @return array{0: string, 1: string} le code de la carte et son droit d'accès
     */
    private function sellAndPairACardAtTheCounter(Client $client, array $staff): array
    {
        $session = $this->ouvrirSession($client, $staff);
        $sale = $this->creerVente($client, $staff, $session['id']);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/lignes', $staff + ['json' => [
            'produit' => '/api/produits/' . $this->idProduit(OffreFixtures::PRODUIT_CARTE),
            'typeTarif' => '/api/type_tarifs/' . $this->idTarif(OffreFixtures::TARIF_PLEIN),
            'quantite' => 1,
        ]]);
        $client->request('POST', '/api/ventes/' . $sale['id'] . '/paiements', $staff + ['json' => ['moyen' => 'especes', 'montant' => '45.00']]);
        $valid = $client->request('POST', '/api/ventes/' . $sale['id'] . '/valider', $staff + ['json' => []])->toArray();
        $code = $valid['supports'][0]['identifiantSupport'];

        $support = $this->entite(BilletSupport::class, ['identifiantSupport' => $code]);
        $pairing = $client->request('POST', '/api/acces/appairages', $staff + ['json' => [
            'identifiantSupport' => $code,
            'typeSupport' => 'QR',
            'billetSupportRef' => (string) $support->getId(),
            'mode' => 'caisse',
        ]]);
        self::assertResponseIsSuccessful((string) $pairing->getContent(false));

        return [$code, (string) $pairing->toArray()['droit']['id']];
    }

    private function openRecharge(Client $client, string $token, string $cardCode): string
    {
        [, $config] = $this->device($client, $token, 'GET', '/config');
        self::assertTrue($config['rechargeEnabled']);
        $offer = $this->offerOf($config, OffreFixtures::PRODUIT_CARTE, OffreFixtures::TARIF_PLEIN);
        [$status, $order] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $offer, 'quantity' => 1]], 'cardCode' => $cardCode]);
        self::assertSame(201, $status, json_encode($order));
        self::assertTrue($order['recharge']);

        return $order['order'];
    }
}
