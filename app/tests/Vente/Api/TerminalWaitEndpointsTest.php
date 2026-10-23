<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\DataFixtures\SocleFixtures;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalOutcomeSink;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * CE QUE L'ÉCRAN FAIT DE L'ATTENTE DU TERMINAL (spec TPE lot 1, CA-2, CA-3, CA-5, CA-8) : il la
 * scrute (`GET /ventes/{id}/reglement-terminal`) et peut l'annuler (`POST …/annuler-reglement`).
 *
 * Le terminal simulé reste muet avec `X-Tpe-Simule: attente` ; les issues tardives sont livrées ici
 * par le chemin de l'issue, comme le ferait un connecteur réel. Les témoins sont lus EN BASE.
 */
final class TerminalWaitEndpointsTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** CA-2 — la scrutation ne juge pas perdue une attente de 95 s, puis montre l'issue : débité, autorisation, réseau, ticket, reste dû. */
    public function testThePollShowsTheWaitThenTheOutcome(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);
        $this->age($tentative, '-95 seconds');

        self::assertSame(['sent', 'simule'], [$this->poll($client, $entete, $vente, $tentative)['statut'] ?? null, $this->poll($client, $entete, $vente, $tentative)['connecteur'] ?? null]);
        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-95', numeroAutorisation: 'A12345', reseau: 'CB', ticketClient: "CARTE BANCAIRE\nMONTANT 45,00 EUR"));

        $lu = $this->poll($client, $entete, $vente, $tentative);
        self::assertSame(['accepted', '45.00', 'A12345', 'CB', '0.00'], [$lu['statut'], $lu['montantDebite'], $lu['numeroAutorisation'], $lu['reseau'], $lu['resteAPayer']]);
        self::assertStringContainsString('MONTANT 45,00 EUR', (string) $lu['ticketClient']);
        self::assertNotNull($lu['paiement']);
    }

    /** Passé le délai du connecteur, la scrutation juge l'attente : `unresolved`, la vente reste tenue et se déclare. */
    public function testAWaitPastTheConnectorDelayBecomesUnresolved(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);
        $this->age($tentative, '-121 seconds');

        $lu = $this->poll($client, $entete, $vente, $tentative);

        self::assertSame('unresolved', $lu['statut']);
        self::assertStringContainsString('Sans issue après 120 s', (string) $lu['raison']);
    }

    /**
     * CA-3 — « Annuler » pendant l'attente : `cancelled`, la vente est libérée. L'acceptation qui arrive
     * ensuite GAGNE : le client a été débité, le règlement s'écrit, une seule fois, et la scrutation le dit.
     */
    public function testCancelWhileWaitingThenALateAcceptanceWinsOnce(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);

        $annulation = $this->cancel($client, $entete, $vente, $tentative);
        self::assertSame(200, $annulation->getStatusCode(), $annulation->getContent(false));
        self::assertSame('cancelled', $annulation->toArray()['tentative']['statut'] ?? null);
        self::assertSame(['cancelled', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sale_payment_attempt WHERE open_sale_id = UNHEX(:v)', ['v' => $this->hex($vente)]), 'La vente est libérée.');
        self::assertSame([200, true], [$this->cancel($client, $entete, $vente, $tentative)->getStatusCode(), $this->cancel($client, $entete, $vente, $tentative)->toArray()['dejaEnregistre']], 'Rejouée, l\'annulation rend son issue.');

        $acceptation = new ResultatTpe(StatutTPE::Accepte, 'TX-TARD');
        $this->sink()->record($this->hex($tentative), $acceptation);
        $this->sink()->record($this->hex($tentative), $acceptation); // livrée deux fois (rejeu d'un connecteur)

        self::assertSame(['accepted', 1], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        $lu = $this->poll($client, $entete, $vente, $tentative);
        self::assertSame(['accepted', '0.00'], [$lu['statut'], $lu['resteAPayer']]);
        self::assertStringContainsString('après l\'annulation', (string) $lu['raison'], 'L\'écran doit pouvoir dire que l\'acceptation a gagné.');
    }

    /**
     * CA-3, « pas de double règlement » — annulée, la vente a été réglée en espèces ; le terminal
     * accepte ENSUITE : la vente n'est pas réglée deux fois. Rien ne s'écrit, la tentative reste
     * annulée et dit au caissier que le client a été débité et doit être remboursé.
     */
    public function testALateAcceptanceOnASaleSettledMeanwhileIsNotWrittenTwice(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);
        self::assertSame(200, $this->cancel($client, $entete, $vente, $tentative)->getStatusCode());
        self::assertSame(201, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00'])->getStatusCode());

        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-DOUBLE'));
        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-DOUBLE'));

        self::assertSame(['cancelled', 1], [$this->statusOf($tentative), $this->paymentCount($vente)], 'Un seul règlement : les espèces.');
        $lu = $this->poll($client, $entete, $vente, $tentative);
        self::assertStringContainsString('client débité de 45,00 € sans règlement, à rembourser', (string) $lu['raison']);
        self::assertSame(1, substr_count((string) $this->db()->fetchOne('SELECT failure_reason FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]), 'à rembourser'), 'Livrée deux fois, notée une fois.');
    }

    /** CA-3 — l'acceptation arrivée AVANT l'annulation : l'annulation est refusée et dit pourquoi ; rien n'est défait. */
    public function testCancelAfterTheTerminalAcceptedIsRefused(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);
        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-1'));

        $annulation = $this->cancel($client, $entete, $vente, $tentative);

        self::assertSame([409, 'payment_outcome_known', 'accepted'], [$annulation->getStatusCode(), $annulation->toArray(false)['code'] ?? null, $annulation->toArray(false)['tentative']['statut'] ?? null]);
        self::assertSame(['accepted', 1], [$this->statusOf($tentative), $this->paymentCount($vente)]);
    }

    /** CA-5 — sans identifiant, la lecture rend la tentative qui tient la vente : un autre poste, ou l'écran rechargé, la retrouve. */
    public function testWithoutAnIdThePollReturnsTheAttemptHoldingTheSale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        self::assertSame(404, $client->request('GET', '/api/ventes/' . $vente . '/reglement-terminal', $entete)->getStatusCode(), 'Rien n\'attend encore.');
        $tentative = $this->waiting($client, $entete, $vente);

        $courante = $client->request('GET', '/api/ventes/' . $vente . '/reglement-terminal', $entete);

        self::assertSame([200, $tentative, 'sent'], [$courante->getStatusCode(), $courante->toArray(false)['id'] ?? null, $courante->toArray(false)['statut'] ?? null]);
    }

    /** CA-8 — la tentative d'un autre établissement est introuvable : lecture et annulation rendent 404, rien ne bouge. */
    public function testAnAttemptOfAnotherEstablishmentIsNotFound(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $vente = $this->cardSale($client, $entete, $session);
        $tentative = $this->waiting($client, $entete, $vente);
        // Le même administrateur, travaillant sur B : la vente de A n'existe pas pour lui.
        $surB = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];
        // Témoin positif : la même lecture, sur A, répond — un 404 plus bas ne vient pas d'une route absente.
        self::assertSame('sent', $this->poll($client, $entete, $vente, $tentative)['statut'] ?? null);

        self::assertSame(404, $client->request('GET', '/api/ventes/' . $vente . '/reglement-terminal?tentative=' . $tentative, $surB)->getStatusCode());
        self::assertSame(404, $client->request('GET', '/api/ventes/' . $vente . '/reglement-terminal', $surB)->getStatusCode());
        self::assertSame(404, $this->cancel($client, $surB, $vente, $tentative)->getStatusCode());
        // Une vente visible ne prête pas son identifiant à la tentative d'une autre vente.
        $autre = $this->cardSale($client, $entete, $session);
        self::assertSame(404, $client->request('GET', '/api/ventes/' . $autre . '/reglement-terminal?tentative=' . $tentative, $entete)->getStatusCode());
        self::assertSame(422, $this->cancel($client, $entete, $autre, $tentative)->getStatusCode());
        self::assertSame('sent', $this->statusOf($tentative));
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

    /**
     * @param array<string, mixed> $entete
     *
     * @return array<string, mixed>
     */
    private function poll(Client $client, array $entete, string $vente, string $tentative): array
    {
        $reponse = $client->request('GET', '/api/ventes/' . $vente . '/reglement-terminal?tentative=' . $tentative, $entete);
        self::assertSame(200, $reponse->getStatusCode(), $reponse->getContent(false));

        return $reponse->toArray();
    }

    /** @param array<string, mixed> $entete */
    private function cancel(Client $client, array $entete, string $vente, string $tentative): ResponseInterface
    {
        return $client->request('POST', '/api/ventes/' . $vente . '/annuler-reglement', $entete + ['json' => ['tentative' => $tentative]]);
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
}
