<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalOutcomeSink;
use App\Vente\Tpe\TpeMock;
use Symfony\Component\Uid\Uuid;

/**
 * LE TERMINAL DE PAIEMENT RÉPOND PLUS TARD (spec TPE lot 1, CA-1, CA-2, CA-4, CA-5, CA-9).
 *
 * Une demande carte part au terminal et rend la main tout de suite : la tentative passe `sent`, et
 * son issue arrive ensuite par un chemin unique (`TerminalOutcomeSink`), quel qu'en soit le délai.
 * Le terminal simulé reste muet avec `X-Tpe-Simule: attente` ; les issues tardives sont livrées ici
 * par ce chemin, comme le ferait un connecteur réel. Les témoins sont lus EN BASE.
 */
final class TerminalAsyncTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** CA-1 — la demande rend la main en moins d'une seconde ; la tentative est `sent` et tient la vente, rien n'est encaissé. */
    public function testACardRequestReturnsAtOnceAndTheAttemptIsSent(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();

        $debut = microtime(true);
        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'attente');
        $duree = microtime(true) - $debut;

        self::assertSame(202, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertLessThan(1.0, $duree, 'La demande ne doit pas attendre le terminal.');
        $corps = $reponse->toArray(false);
        self::assertSame([false, true, 'sent'], [$corps['reglementEnregistre'], $corps['enAttente'] ?? null, $corps['tentative']['statut'] ?? null]);
        self::assertSame(['sent', 1], [$this->attemptOf($cle)['status'] ?? null, (int) ($this->attemptOf($cle)['holds_sale'] ?? -1)]);
        self::assertSame(0, $this->paymentCount($vente));
    }

    /** CA-2 — une acceptation arrivée 95 s plus tard s'écrit : un règlement, la tentative `accepted` avec ce que le terminal a rendu. */
    public function testAnOutcomeArrivingAfterNinetySecondsIsRecorded(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);
        $this->age($tentative, '-95 seconds');

        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-95', numeroAutorisation: 'A12345', reseau: 'CB', ticketClient: "CARTE BANCAIRE\nMONTANT 45,00 EUR", ticketCommercant: 'COPIE COMMERCANT'));

        self::assertSame(1, $this->paymentCount($vente));
        $ligne = $this->db()->fetchAssociative(
            'SELECT status, open_sale_id IS NULL libre, debited_amount, authorization_number, card_network, customer_receipt, merchant_receipt, connector FROM sale_payment_attempt WHERE id = UNHEX(:id)',
            ['id' => $this->hex($tentative)],
        );
        self::assertSame(['accepted', 1, '45.00', 'A12345', 'CB', 'COPIE COMMERCANT', 'simule'], [$ligne['status'], (int) $ligne['libre'], $ligne['debited_amount'], $ligne['authorization_number'], $ligne['card_network'], $ligne['merchant_receipt'], $ligne['connector']]);
        self::assertStringContainsString('MONTANT 45,00 EUR', (string) $ligne['customer_receipt']);
        self::assertSame(['TX-95', '0.00'], [
            $this->db()->fetchOne('SELECT ref_tpe FROM vente_paiement WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]),
            $this->db()->fetchOne('SELECT reste_apayer FROM vente_vente WHERE id = UNHEX(:v)', ['v' => $this->hex($vente)]),
        ]);
    }

    /** CA-4 — 30,00 débités sur 45,00 demandés : un règlement de 30,00, le reste dû vaut 15,00 ; le rejeu de la clé rend ce règlement. */
    public function testAPartialDebitSettlesWhatWasDebitedAndLeavesTheRest(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $this->mock()->programmer(new ResultatTpe(StatutTPE::Accepte, 'TX-P', montantDebite: '30.00'));
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle = (string) Uuid::v4()];

        $reponse = $this->pay($client, $entete, $vente, $corps);

        self::assertSame(201, $reponse->getStatusCode(), $reponse->getContent(false));
        self::assertSame(['30.00', '15.00'], [$reponse->toArray()['montant'], $reponse->toArray()['resteAPayer']]);
        self::assertSame(['accepted', '30.00'], [$this->attemptOf($cle)['status'] ?? null, $this->db()->fetchOne('SELECT debited_amount FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k)', ['k' => $this->hex($cle)])]);
        $rejeu = $this->pay($client, $entete, $vente, $corps);
        self::assertSame([200, true, '30.00'], [$rejeu->getStatusCode(), $rejeu->toArray(false)['dejaEnregistre'] ?? null, $rejeu->toArray(false)['montant'] ?? null]);
        self::assertSame(1, $this->paymentCount($vente));
        $this->mock()->programmer(null);
    }

    /** Un montant débité SUPÉRIEUR au montant demandé n'est pas un règlement : la tentative reste sans issue, à déclarer. */
    public function testADebitAboveTheRequestedAmountIsNotSettled(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);

        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-X', montantDebite: '50.00'));

        self::assertSame(['unresolved', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
    }

    /** CA-5 — après un rechargement, la même demande (même clé) retrouve l'attente, sans repartir au terminal. */
    public function testAReloadFindsTheWaitingAttempt(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()];
        $premiere = $this->pay($client, $entete, $vente, $corps, 'attente')->toArray(false);

        // Forcé en refus : sollicité une seconde fois, le terminal aurait refusé.
        $reprise = $this->pay($client, $entete, $vente, $corps, 'refuse');

        self::assertSame(202, $reprise->getStatusCode(), $reprise->getContent(false));
        self::assertSame($premiere['tentative']['id'], $reprise->toArray(false)['tentative']['id'] ?? null);
        self::assertSame(['sent', 0], [$this->statusOf($premiere['tentative']['id']), $this->paymentCount($vente)]);
    }

    /**
     * CA-9 — un règlement carte PARTIEL complété en espèces : la vente se valide, la chaîne NF525 reste
     * intacte et le Z compte les 45,00 (30,00 carte, 15,00 espèces).
     */
    public function testAPartialCardPaymentKeepsTheChainAndTheClosure(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $vente = $this->cardSale($client, $entete, $session);
        $tentative = $this->waiting($client, $entete, $vente);
        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-30', montantDebite: '30.00'));
        $especes = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '15.00']);
        self::assertSame([201, '0.00'], [$especes->getStatusCode(), $especes->toArray(false)['resteAPayer'] ?? null]);

        $client->request('POST', '/api/ventes/' . $vente . '/valider', $entete + ['json' => []]);
        self::assertResponseIsSuccessful();
        $chaine = $client->request('POST', '/api/nf525/verifier-chaine', $entete + [
            'json' => ['pointDeVente' => '/api/point_de_ventes/' . $this->idPointDeVente()],
        ])->toArray();
        self::assertTrue($chaine['intacte']);
        self::assertEmpty($chaine['anomalies']);

        $z = $client->request('POST', '/api/sessions-caisse/' . $session . '/cloturer', $entete + ['json' => [
            'comptages' => [['moyen' => 'especes', 'compte' => '65.00'], ['moyen' => 'cb', 'compte' => '30.00']], 'versement' => '0.00',
        ]]);
        self::assertSame(200, $z->getStatusCode(), $z->getContent(false));
        self::assertSame(['45.00', '0.00'], [$z->toArray()['totalVentes'], $z->toArray()['ecartTotal']]);
    }

    /**
     * Une carte qui attend le terminal (`X-Tpe-Simule: attente`).
     *
     * @param array<string, mixed> $entete
     *
     * @return string l'identifiant de la tentative, tel que l'écran le reçoit
     */
    private function waiting(Client $client, array $entete, string $vente): string
    {
        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()], 'attente');
        self::assertSame(202, $reponse->getStatusCode(), 'témoin : le terminal n\'a pas encore répondu — ' . $reponse->getContent(false));

        return (string) $reponse->toArray(false)['tentative']['id'];
    }

    private function age(string $tentative, string $age): void
    {
        $this->db()->executeStatement('UPDATE sale_payment_attempt SET started_at = :d WHERE id = UNHEX(:id)', [
            'd' => (new \DateTimeImmutable($age))->format('Y-m-d H:i:s'), 'id' => $this->hex($tentative),
        ]);
    }

    private function statusOf(string $tentative): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);
    }

    private function sink(): TerminalOutcomeSink
    {
        $sink = static::getContainer()->get(TerminalOutcomeSink::class);
        self::assertInstanceOf(TerminalOutcomeSink::class, $sink);

        return $sink;
    }

    private function mock(): TpeMock
    {
        $mock = static::getContainer()->get(TpeMock::class);
        self::assertInstanceOf(TpeMock::class, $mock);

        return $mock;
    }
}
