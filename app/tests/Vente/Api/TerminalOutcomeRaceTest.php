<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\InterleaveOnLoad;
use App\Tests\Vente\Support\SettlementScenarios;
use App\Vente\Entity\Vente;
use App\Vente\Enum\StatutTPE;
use App\Vente\Tpe\ResultatTpe;
use App\Vente\Tpe\TerminalOutcomeSink;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Symfony\Component\Uid\Uuid;

/**
 * L'ACCEPTATION TARDIVE NE RÈGLE JAMAIS DEUX FOIS, NI HORS NF525, NI HORS Z (relecture du lot TPE, D1, D2).
 *
 * Le chemin de l'issue lisait le statut de la tentative HORS de sa transaction, et l'écriture acceptait
 * aussi `cancelled`. Une autre connexion qui, dans cet intervalle, annule la tentative et encaisse la
 * vente autrement — ou la valide — laissait passer un second règlement. La fenêtre est rendue
 * déterministe : l'autre connexion agit au chargement de la vente (`InterleaveOnLoad`), après la lecture
 * de la tentative et avant la transaction. Témoins lus EN BASE.
 */
final class TerminalOutcomeRaceTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** D1 — annulée et payée en espèces pendant l'acceptation : un seul règlement (45 €, pas 90 €), et « à rembourser ». */
    public function testACancelAndCashPaymentDuringTheAcceptanceSettleTheSaleOnce(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);

        $this->duringTheAcceptance($vente, function (Connection $autre) use ($vente, $tentative): void {
            $this->cancelElsewhere($autre, $tentative);
            $autre->executeStatement(
                "INSERT INTO vente_paiement (id, vente_id, moyen_code, montant, rendu, differe, date_heure) VALUES (UNHEX(:id), UNHEX(:v), 'especes', '45.00', '0.00', 0, NOW())",
                ['id' => $this->hex((string) Uuid::v4()), 'v' => $this->hex($vente)],
            );
            $autre->executeStatement("UPDATE vente_vente SET reste_apayer = '0.00' WHERE id = UNHEX(:v)", ['v' => $this->hex($vente)]);
        });

        self::assertSame('45.00', $this->db()->fetchOne('SELECT SUM(montant) FROM vente_paiement WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]), 'La vente est réglée une fois.');
        self::assertSame(['cancelled', 1], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        self::assertStringContainsString('à rembourser', (string) $this->reasonOf($tentative));
    }

    /** D1 — annulée et VALIDÉE pendant l'acceptation : rien ne s'écrit sur la vente scellée (NF525). */
    public function testACancelAndValidationDuringTheAcceptanceWriteNothingOnASealedSale(): void
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->waiting($client, $entete, $vente);

        $this->duringTheAcceptance($vente, function (Connection $autre) use ($vente, $tentative): void {
            $this->cancelElsewhere($autre, $tentative);
            $autre->executeStatement("UPDATE vente_vente SET statut = 'validee' WHERE id = UNHEX(:v)", ['v' => $this->hex($vente)]);
        });

        self::assertSame(['cancelled', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        self::assertStringContainsString('à rembourser', (string) $this->reasonOf($tentative));
    }

    /**
     * D2 — annulée, puis la session est close (Z), puis le terminal accepte : rien ne s'écrit sur une
     * session close (hors Z, hors régie) ; la tentative dit « client débité, à rembourser ».
     */
    public function testAnAcceptanceAfterTheSessionClosedIsNotWritten(): void
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete)['id'];
        $vente = $this->cardSale($client, $entete, $session);
        $tentative = $this->waiting($client, $entete, $vente);
        // Annulée il y a dix minutes : au-delà de toute veille, le Z peut passer.
        $this->db()->executeStatement(
            "UPDATE sale_payment_attempt SET status = 'cancelled', open_sale_id = NULL, closed_at = :d WHERE id = UNHEX(:id)",
            ['d' => (new \DateTimeImmutable('-10 minutes'))->format('Y-m-d H:i:s'), 'id' => $this->hex($tentative)],
        );
        $z = $client->request('POST', '/api/sessions-caisse/' . $session . '/cloturer', $entete + ['json' => [
            'comptages' => [['moyen' => 'especes', 'compte' => '50.00']], 'versement' => '0.00',
        ]]);
        self::assertSame(200, $z->getStatusCode(), 'témoin : le Z est passé — ' . $z->getContent(false));

        $this->sink()->record($this->hex($tentative), new ResultatTpe(StatutTPE::Accepte, 'TX-APRES-Z'));

        self::assertSame(['cancelled', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        self::assertStringContainsString('à rembourser', (string) $this->reasonOf($tentative));
    }

    /** L'acceptation livrée pendant que l'autre connexion agit (au chargement de la vente). */
    private function duringTheAcceptance(string $vente, \Closure $geste): void
    {
        $autre = DriverManager::getConnection($this->db()->getParams());
        $entrelacement = static::getContainer()->get(InterleaveOnLoad::class);
        self::assertInstanceOf(InterleaveOnLoad::class, $entrelacement);
        $joue = false;
        $entrelacement->arm(Vente::class, function () use ($geste, $autre, &$joue): void {
            $geste($autre);
            $joue = true;
        });
        try {
            $this->sink()->record($this->hex($this->attemptIdOf($vente)), new ResultatTpe(StatutTPE::Accepte, 'TX-COURSE'));
        } finally {
            $autre->close();
        }
        self::assertTrue($joue, 'témoin : l\'autre connexion a bien agi pendant l\'acceptation');
    }

    private function cancelElsewhere(Connection $autre, string $tentative): void
    {
        $autre->executeStatement(
            "UPDATE sale_payment_attempt SET status = 'cancelled', terminal_status = 'annule', open_sale_id = NULL, closed_at = NOW() WHERE id = UNHEX(:id)",
            ['id' => $this->hex($tentative)],
        );
    }

    /** @param array<string, mixed> $entete */
    private function waiting(Client $client, array $entete, string $vente): string
    {
        $reponse = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()], 'attente');
        self::assertSame(202, $reponse->getStatusCode(), 'témoin : le terminal n\'a pas encore répondu — ' . $reponse->getContent(false));

        return (string) $reponse->toArray(false)['tentative']['id'];
    }

    private function attemptIdOf(string $vente): string
    {
        $hex = (string) $this->db()->fetchOne('SELECT LOWER(HEX(id)) FROM sale_payment_attempt WHERE sale_id = UNHEX(:v) ORDER BY started_at DESC LIMIT 1', ['v' => $this->hex($vente)]);

        return Uuid::fromBinary((string) hex2bin($hex))->toRfc4122();
    }

    private function statusOf(string $tentative): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);
    }

    private function reasonOf(string $tentative): ?string
    {
        $raison = $this->db()->fetchOne('SELECT failure_reason FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);

        return \is_string($raison) ? $raison : null;
    }

    private function sink(): TerminalOutcomeSink
    {
        $sink = static::getContainer()->get(TerminalOutcomeSink::class);
        self::assertInstanceOf(TerminalOutcomeSink::class, $sink);

        return $sink;
    }
}
