<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * LE TERMINAL PILOTÉ PAR LE PONT LOCAL (connecteur « caisse_ap_pont », #340).
 *
 * Le pont (`bridge/caisse-ap`) parle Caisse-AP au terminal et signe l'issue ; le navigateur la relaie.
 * Ici, le pont est remplacé par sa clé : chaque test signe l'issue comme le pont la signerait
 * (Ed25519 sur le texte JSON). Ce qui est prouvé : seule une issue signée par le pont DE CE point de
 * vente, pour CETTE tentative et CE montant, compte ; un refus ne valide jamais la vente.
 */
final class LocalBridgeTerminalTest extends CrmApiTestCase
{
    use SettlementScenarios;

    private string $secretKey = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->secretKey = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    }

    /** Le montant part au pont, le résultat revient sans ressaisie : une acceptation signée écrit le règlement. */
    public function testASignedAcceptanceSettlesTheSale(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);
        self::assertSame(['sent', 'caisse_ap_pont'], [$tentative['statut'], $tentative['connecteur']]);

        $reponse = $this->relay($client, $entete, $vente, $this->signed($tentative['id'], '45.00', 'accepte', ['debitedAmount' => '45.00', 'authorization' => '123456', 'network' => 'CB sans contact']));

        self::assertSame(200, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertSame(['accepted', 1], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
        self::assertSame('CAP-123456', $this->db()->fetchOne('SELECT ref_tpe FROM vente_paiement WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]));
        $valider = $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => new \stdClass()]);
        self::assertLessThan(300, $valider->getStatusCode(), $valider->getContent(false));
        self::assertSame('validee', $this->db()->fetchOne('SELECT statut FROM vente_vente WHERE id = UNHEX(:v)', ['v' => $this->hex($vente)]), 'Témoin positif du témoin de refus.');

        $rejeu = $this->relay($client, $entete, $vente, $this->signed($tentative['id'], '45.00', 'accepte', ['debitedAmount' => '45.00', 'authorization' => '123456']));
        self::assertSame([200, 1], [$rejeu->getStatusCode(), $this->paymentCount($vente)], 'Relayée deux fois, un seul règlement.');
    }

    /** LE TÉMOIN DU TICKET : un refus du terminal ne valide pas la vente. */
    public function testATerminalRefusalDoesNotValidateTheSale(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);

        $reponse = $this->relay($client, $entete, $vente, $this->signed($tentative['id'], '45.00', 'refuse', ['ae' => '01', 'af' => '04']));

        self::assertSame(200, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertSame(['refused', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
        $valider = $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => new \stdClass()]);
        self::assertGreaterThanOrEqual(400, $valider->getStatusCode(), 'Rien n\'est réglé : la vente ne se valide pas.');
        self::assertSame('en_cours', $this->db()->fetchOne('SELECT statut FROM vente_vente WHERE id = UNHEX(:v)', ['v' => $this->hex($vente)]));
        self::assertSame(201, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00'])->getStatusCode(), 'La vente est libre pour un autre moyen.');
    }

    /** Le navigateur transporte l'issue, il ne la change pas : un refus réécrit en acceptation est rejeté. */
    public function testATamperedOutcomeIsRejected(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);
        $refus = $this->signed($tentative['id'], '45.00', 'refuse');
        $falsifie = ['resultat' => str_replace('"refuse"', '"accepte"', $refus['resultat']), 'signature' => $refus['signature']];

        $reponse = $this->relay($client, $entete, $vente, $falsifie);

        self::assertSame(422, $reponse->getStatusCode());
        self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
    }

    /** Une acceptation signée par une AUTRE clé (un autre poste, un autre client) n'est pas reçue. */
    public function testAnOutcomeSignedByAnotherBridgeIsRejected(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);
        $autre = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $reponse = $this->relay($client, $entete, $vente, $this->signed($tentative['id'], '45.00', 'accepte', [], $autre));

        self::assertSame(422, $reponse->getStatusCode());
        self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
    }

    /** Une issue signée pour une autre tentative, ou un autre montant, n'est pas celle-ci. */
    public function testAnOutcomeOfAnotherAttemptOrAmountIsRejected(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);

        $autreTentative = $this->signed((string) Uuid::v4(), '45.00', 'accepte');
        self::assertSame(422, $this->relay($client, $entete, $vente, $autreTentative, $tentative['id'])->getStatusCode());
        $autreMontant = $this->signed($tentative['id'], '4.50', 'accepte');
        self::assertSame(422, $this->relay($client, $entete, $vente, $autreMontant)->getStatusCode());
        self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
    }

    /** « timeout » (le pont n'a rien pu lire) : la tentative est à déclarer, la vente reste tenue. */
    public function testAnUnknownOutcomeHoldsTheSaleForADeclaration(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);

        self::assertSame(200, $this->relay($client, $entete, $vente, $this->signed($tentative['id'], '45.00', 'timeout'))->getStatusCode());

        self::assertSame(['unresolved', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
        self::assertSame(409, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00'])->getStatusCode(), 'Tenue jusqu\'à la déclaration.');
    }

    /** Cloisonnement : un utilisateur d'un autre client ne relaie rien sur la vente de A, même signée. */
    public function testAnotherCustomerCannotRelayAnOutcome(): void
    {
        [$client, $entete] = $this->bridgeOnA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->startCard($client, $entete, $vente);
        [$clientB, $enteteB] = $this->agentSurGroupeB();

        $reponse = $clientB->request('POST', '/api/ventes/' . $vente . '/issue-pont', $enteteB + ['json' => ['tentative' => $tentative['id']] + $this->signed($tentative['id'], '45.00', 'accepte')]);

        self::assertContains($reponse->getStatusCode(), [403, 404]);
        self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
    }

    /** Sans clé du pont, le connecteur ne se règle pas ; et un point de vente sans clé n'envoie rien. */
    public function testTheBridgeNeedsItsKey(): void
    {
        [$client, $entete] = $this->adminSurA();
        $sansCle = $this->configure($client, $entete, ['connecteur' => 'caisse_ap_pont']);
        self::assertSame(422, $sansCle->getStatusCode());
        self::assertStringContainsString('clePont', $sansCle->getContent(false));
        self::assertSame(422, $this->configure($client, $entete, ['connecteur' => 'caisse_ap_pont', 'clePont' => base64_encode('court')])->getStatusCode());
        self::assertSame(422, $this->configure($client, $entete, ['connecteur' => 'caisse_ap_pont', 'clePont' => $this->publicKey(), 'pont' => 'http://192.168.1.5:8765'])->getStatusCode(), 'Le pont est sur le poste lui-même.');
        self::assertSame(200, $this->configure($client, $entete, ['connecteur' => 'caisse_ap_pont', 'clePont' => $this->publicKey(), 'pont' => 'http://127.0.0.1:8765'])->getStatusCode());
    }

    /** @return array{0: Client, 1: array<string, mixed>} */
    private function bridgeOnA(): array
    {
        [$client, $entete] = $this->adminSurA();
        $reponse = $this->configure($client, $entete, ['connecteur' => 'caisse_ap_pont', 'clePont' => $this->publicKey(), 'marque' => 'ingenico']);
        self::assertSame(200, $reponse->getStatusCode(), $reponse->getContent(false));

        return [$client, $entete];
    }

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function startCard(Client $client, array $entete, string $vente): array
    {
        $demande = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        self::assertSame(202, $demande->getStatusCode(), $demande->getContent(false));

        return $demande->toArray(false)['tentative'];
    }

    private function publicKey(): string
    {
        return base64_encode(sodium_crypto_sign_publickey_from_secretkey($this->secretKey));
    }

    /**
     * L'issue telle que le pont la rend (bridge/caisse-ap/src/server.js, runPayment).
     *
     * @param array<string, mixed> $extra
     *
     * @return array{resultat: string, signature: string}
     */
    private function signed(string $attempt, string $amount, string $status, array $extra = [], ?string $secretKey = null): array
    {
        $resultat = (string) json_encode(['v' => 1, 'bridge' => 'fluvia-caisse-ap', 'attempt' => $attempt, 'requestedAmount' => $amount, 'currency' => 'EUR', 'status' => $status] + $extra + ['terminal' => 'TPE-TEST', 'at' => date(\DATE_ATOM)]);

        return ['resultat' => $resultat, 'signature' => base64_encode(sodium_crypto_sign_detached($resultat, $secretKey ?? $this->secretKey))];
    }

    /**
     * @param array<string, mixed>                       $entete
     * @param array{resultat: string, signature: string} $issue
     */
    private function relay(Client $client, array $entete, string $vente, array $issue, ?string $tentative = null): ResponseInterface
    {
        $tentative ??= (string) json_decode($issue['resultat'], true)['attempt'];

        return $client->request('POST', '/api/ventes/' . $vente . '/issue-pont', $entete + ['json' => ['tentative' => $tentative] + $issue]);
    }

    /**
     * @param array<string, mixed>      $entete
     * @param array<mixed, mixed>|null  $tpe
     */
    private function configure(Client $client, array $entete, ?array $tpe): ResponseInterface
    {
        $options = $entete;
        $options['headers']['Content-Type'] = 'application/merge-patch+json';
        $options['json'] = ['tpe' => $tpe];

        return $client->request('PATCH', '/api/point_de_ventes/' . $this->idPointDeVente(), $options);
    }

    private function statusOf(string $tentative): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);
    }
}
