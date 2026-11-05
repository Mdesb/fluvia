<?php

declare(strict_types=1);

namespace App\Tests\Kiosk\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Caisse\Entity\PointDeVente;
use App\DataFixtures\SocleFixtures;
use App\Kiosk\DataFixtures\KioskFixtures;
use App\Offre\DataFixtures\OffreFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Kiosk\KioskTestTrait;
use App\Tests\Vente\VenteApiTestCase;
use App\Vente\Entity\BilletSupport;
use App\Vente\Enum\StatutVente;
use Symfony\Component\Uid\Uuid;

/**
 * La borne branchée sur le TERMINAL PILOTÉ PAR LE PONT LOCAL (#340, connecteur `caisse_ap_pont`) :
 * l'écran de la borne relaie l'issue signée (Ed25519) du pont, comme la caisse. La vente ne se délivre
 * que sur une acceptation signée par le pont de CE point de vente ; un refus, une issue falsifiée ou
 * relayée par une autre borne ne délivrent rien.
 */
final class KioskBridgeTerminalTest extends VenteApiTestCase
{
    use KioskTestTrait;

    private string $secretKey;

    protected function setUp(): void
    {
        parent::setUp();
        $this->loadKioskFixtures();
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        $em = $this->kioskEm();
        $pos = $em->getRepository(PointDeVente::class)->findOneBy(['libelle' => KioskFixtures::POS_A_LABEL]);
        self::assertInstanceOf(PointDeVente::class, $pos);
        $pos->setTpe([
            'connecteur' => 'caisse_ap_pont',
            'clePont' => base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey)),
            'pont' => 'http://127.0.0.1:8765',
        ]);
        $em->flush();
        self::ensureKernelShutdown();
    }

    public function testASignedAcceptanceDeliversTheTicket(): void
    {
        [$client, $token, $saleId, $attempt] = $this->paymentSentToTheBridge();

        [$status, $relayed] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'accepte', ['authorization' => 'A1B2C3']));
        self::assertSame(200, $status, json_encode($relayed));
        self::assertSame('accepted', $relayed['status']);

        // Relayée deux fois (réseau qui coupe) : un seul règlement.
        $this->device($client, $token, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'accepte', ['authorization' => 'A1B2C3']));
        self::assertCount(1, $this->sale($saleId)->getPaiements());

        [$status, $done] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(200, $status, json_encode($done));
        self::assertNotEmpty($done['ticket']['supports']);
        self::assertSame(StatutVente::Validee, $this->sale($saleId)->getStatut());
    }

    public function testABridgeRefusalDeliversNothing(): void
    {
        [$client, $token, $saleId, $attempt] = $this->paymentSentToTheBridge();

        [$status, $relayed] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'refuse'));
        self::assertSame(200, $status);
        self::assertSame('refused', $relayed['status']);

        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status);
        self::assertCount(0, $this->kioskEm()->getRepository(BilletSupport::class)->findBy(['vente' => $this->sale($saleId)]));
    }

    public function testAForgedOutcomeIsIgnoredAndDeliversNothing(): void
    {
        [$client, $token, $saleId, $attempt] = $this->paymentSentToTheBridge();
        $otherBridge = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'accepte', [], $otherBridge));
        self::assertSame(422, $status, 'Une issue signée par un autre pont est ignorée.');

        [$status, $poll] = $this->device($client, $token, 'GET', '/orders/' . $saleId . '/payments/' . $attempt['id']);
        self::assertSame('waiting', $poll['status']);
        [$status] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/complete');
        self::assertSame(409, $status);
        self::assertCount(0, $this->sale($saleId)->getPaiements());
    }

    public function testAnotherKioskCannotRelayTheOutcome(): void
    {
        [$client, $token, $saleId, $attempt] = $this->paymentSentToTheBridge();
        $staffB = ['auth_bearer' => $this->staffToken, 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];
        $tokenB = $this->pairDevice($client, $staffB, KioskFixtures::KIOSK_B_LABEL);

        [$status] = $this->device($client, $tokenB, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'accepte'));
        self::assertSame(404, $status);
        self::assertCount(0, $this->sale($saleId)->getPaiements());

        // Témoin : la borne propriétaire relaie la même issue, et elle est reçue.
        [$status, $relayed] = $this->device($client, $token, 'POST', '/orders/' . $saleId . '/payments/' . $attempt['id'] . '/bridge-outcome', $this->signed($attempt, 'accepte'));
        self::assertSame(200, $status);
        self::assertSame('accepted', $relayed['status']);
    }

    private string $staffToken = '';

    /** @return array{0: Client, 1: string, 2: string, 3: array<string, mixed>} */
    private function paymentSentToTheBridge(): array
    {
        [$client, $staff] = $this->adminSurA();
        $client->disableReboot();
        $this->staffToken = $staff['auth_bearer'];
        $token = $this->pairDevice($client, $staff, KioskFixtures::KIOSK_A_LABEL);

        [, $config] = $this->device($client, $token, 'GET', '/config');
        self::assertTrue($config['terminalReady'], 'Le terminal du pont est piloté : la borne est en service.');
        self::assertSame('http://127.0.0.1:8765', $config['bridge']);
        [$status, $order] = $this->device($client, $token, 'POST', '/orders', ['items' => [['offer' => $this->offerOf($config, OffreFixtures::PRODUIT_ENTREE, OffreFixtures::TARIF_PLEIN), 'quantity' => 1]]]);
        self::assertSame(201, $status, json_encode($order));

        [$status, $paid] = $this->device($client, $token, 'POST', '/orders/' . $order['order'] . '/pay', ['key' => (string) Uuid::v4()]);
        self::assertSame(202, $status, json_encode($paid));
        self::assertSame('waiting', $paid['status']);
        self::assertSame('caisse_ap_pont', $paid['attempt']['connecteur'], 'L\'écran sait qu\'il doit relayer au pont.');

        return [$client, $token, $order['order'], $paid['attempt']];
    }

    /**
     * L'issue telle que le pont la signe (`bridge/caisse-ap/src/signer.js`).
     *
     * @param array<string, mixed> $attempt
     * @param array<string, mixed> $extra
     *
     * @return array{resultat: string, signature: string}
     */
    private function signed(array $attempt, string $status, array $extra = [], ?string $secretKey = null): array
    {
        $result = (string) json_encode(['v' => 1, 'bridge' => 'fluvia-caisse-ap', 'attempt' => $attempt['id'], 'requestedAmount' => $attempt['montant'], 'currency' => 'EUR', 'status' => $status] + $extra + ['terminal' => 'TPE-BORNE', 'at' => date(\DATE_ATOM)]);

        return ['resultat' => $result, 'signature' => base64_encode(sodium_crypto_sign_detached($result, $secretKey ?? $this->secretKey))];
    }
}
