<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use Symfony\Component\Uid\Uuid;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * LE TERMINAL EXTERNE, DÉCLARATIF (spec TPE lot 1 §5, CA-6) — et `PointDeVente.tpe` structuré (§6).
 *
 * Le CIAP encaisse sur le terminal de sa régie, qui n'est relié à rien : la caisse dit « encaissez
 * XX,XX € sur le terminal », puis le caissier indique ce que le terminal a rendu. Aucune simulation,
 * aucun en-tête, aucune variable d'environnement : le témoin coupe `TPE_SIMULE_AUTORISE`.
 */
final class ExternalTerminalTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** CA-6 — sans aucune variable d'environnement : la carte attend la déclaration, « Accepté » + référence écrit le règlement, signé. */
    public function testWithoutAnyEnvironmentVariableAnAcceptedDeclarationSettles(): void
    {
        $this->sansSimulation(function (Client $client, array $entete): void {
            self::assertSame(200, $this->configure($client, $entete, ['connecteur' => 'externe', 'marque' => 'ingenico', 'ref' => 'CIAP-1'])->getStatusCode());
            $vente = $this->cardSale($client, $entete);

            $demande = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
            self::assertSame(202, $demande->getStatusCode(), $demande->getContent(false));
            $tentative = $demande->toArray(false)['tentative'];
            self::assertSame(['sent', 'externe'], [$tentative['statut'], $tentative['connecteur']]);

            $sansReference = $this->declare($client, $entete, $vente, ['tentative' => $tentative['id'], 'issue' => 'accepte']);
            self::assertSame(422, $sansReference->getStatusCode(), 'La référence du ticket CB est obligatoire.');
            self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);

            $accepte = $this->declare($client, $entete, $vente, ['tentative' => $tentative['id'], 'issue' => 'accepte', 'referenceCarte' => 'CB-778899']);
            self::assertSame(201, $accepte->getStatusCode(), $accepte->getContent(false));
            self::assertSame(['accepted', '45.00', '0.00'], [$accepte->toArray()['issue'], $accepte->toArray()['montant'], $accepte->toArray()['resteAPayer']]);
            $trace = $this->db()->fetchAssociative(
                'SELECT LOWER(HEX(declared_by_id)) par, card_reference, open_sale_id IS NULL libre FROM sale_payment_attempt WHERE id = UNHEX(:id)',
                ['id' => $this->hex($tentative['id'])],
            );
            self::assertSame([$this->hex($this->idAdmin()), 'CB-778899', 1], [$trace['par'], $trace['card_reference'], (int) $trace['libre']]);
            self::assertSame('CB-778899', $this->db()->fetchOne('SELECT ref_tpe FROM vente_paiement WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]));

            $rejeu = $this->declare($client, $entete, $vente, ['tentative' => $tentative['id'], 'issue' => 'accepte', 'referenceCarte' => 'CB-778899']);
            self::assertSame([200, true, 1], [$rejeu->getStatusCode(), $rejeu->toArray(false)['dejaEnregistre'] ?? null, $this->paymentCount($vente)]);
        });
    }

    /** CA-6 — « Refusé » n'écrit aucun règlement : la tentative est close, la vente libre pour un autre moyen. */
    public function testARefusedDeclarationSettlesNothing(): void
    {
        $this->sansSimulation(function (Client $client, array $entete): void {
            $this->configure($client, $entete, ['connecteur' => 'externe']);
            $vente = $this->cardSale($client, $entete);
            $tentative = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()])->toArray(false)['tentative']['id'];

            $refuse = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'refuse']);

            self::assertSame(200, $refuse->getStatusCode(), $refuse->getContent(false));
            self::assertSame([false, 'refused'], [$refuse->toArray()['reglementEnregistre'], $refuse->toArray()['issue']]);
            self::assertSame(['refused', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
            self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM sale_card_rejection WHERE vente_id = UNHEX(:v)', ['v' => $this->hex($vente)]), 'Le refus est consigné (PAY-3).');
            self::assertSame(201, $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00'])->getStatusCode(), 'La vente est libre.');
        });
    }

    /** « Annulé » (le client a renoncé, le terminal a abandonné) : rien n'est écrit, la tentative est annulée. */
    public function testACancelledDeclarationSettlesNothing(): void
    {
        $this->sansSimulation(function (Client $client, array $entete): void {
            $this->configure($client, $entete, ['connecteur' => 'externe']);
            $vente = $this->cardSale($client, $entete);
            $tentative = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()])->toArray(false)['tentative']['id'];

            $annule = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'annule']);

            self::assertSame([200, 'cancelled'], [$annule->getStatusCode(), $annule->toArray(false)['issue'] ?? null]);
            self::assertSame(['cancelled', 0], [$this->statusOf($tentative), $this->paymentCount($vente)]);
        });
    }

    /**
     * CA-3 au terminal externe — « Annuler » pressé trop tôt, le terminal a pourtant accepté : la
     * déclaration « Accepté » gagne et écrit le règlement ; une seconde « Accepté » sous une autre
     * référence ne passe pas pour une création.
     */
    public function testAnAcceptanceDeclaredAfterACancelWinsOnce(): void
    {
        $this->sansSimulation(function (Client $client, array $entete): void {
            $this->configure($client, $entete, ['connecteur' => 'externe']);
            $vente = $this->cardSale($client, $entete);
            $tentative = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()])->toArray(false)['tentative']['id'];
            self::assertSame(200, $client->request('POST', '/api/ventes/' . $vente . '/annuler-reglement', $entete + ['json' => ['tentative' => $tentative]])->getStatusCode());

            $accepte = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => 'CB-TARD']);
            self::assertSame(201, $accepte->getStatusCode(), $accepte->getContent(false));
            self::assertSame(['accepted', 1], [$this->statusOf($tentative), $this->paymentCount($vente)]);

            $autre = $this->declare($client, $entete, $vente, ['tentative' => $tentative, 'issue' => 'accepte', 'referenceCarte' => 'CB-AUTRE']);
            self::assertSame([409, 'payment_outcome_known', 1], [$autre->getStatusCode(), $autre->toArray(false)['code'] ?? null, $this->paymentCount($vente)]);
        });
    }

    /** Un terminal relié (le simulé) ne se déclare pas pendant qu'il attend : seul le terminal externe se lit au caissier. */
    public function testAConnectedTerminalIsNotDeclaredWhileItWaits(): void
    {
        [$client, $entete] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $tentative = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()], 'attente')->toArray(false)['tentative'];
        self::assertSame('simule', $tentative['connecteur']);

        $reponse = $this->declare($client, $entete, $vente, ['tentative' => $tentative['id'], 'issue' => 'accepte', 'referenceCarte' => 'CB-1']);

        self::assertSame([409, 'payment_in_progress'], [$reponse->getStatusCode(), $reponse->toArray(false)['code'] ?? null]);
        self::assertSame(['sent', 0], [$this->statusOf($tentative['id']), $this->paymentCount($vente)]);
    }

    /**
     * §6 — `PointDeVente.tpe` se valide : un connecteur connu ; `simule` refusé hors démonstration ; les
     * connecteurs des lots suivants refusés tant qu'ils n'existent pas ; l'ancienne forme refusée.
     */
    public function testTheTerminalSettingsAreValidated(): void
    {
        $this->sansSimulation(function (Client $client, array $entete): void {
            foreach ([
                [['connecteur' => 'simule'], 'réservé aux démonstrations'],
                [['connecteur' => 'nepting'], 'pas encore disponible'],
                [['connecteur' => 'inconnu'], 'connecteur'],
                [[['marque' => 'ingenico', 'ref' => 'TPE-A1']], 'connecteur'],
                [['marque' => 'ingenico'], 'connecteur'],
            ] as [$tpe, $message]) {
                $reponse = $this->configure($client, $entete, $tpe);
                self::assertSame(422, $reponse->getStatusCode(), (string) json_encode($tpe));
                self::assertStringContainsString($message, $reponse->getContent(false));
            }
            self::assertSame(200, $this->configure($client, $entete, null)->getStatusCode(), 'Sans connecteur : le terminal externe.');
            self::assertSame(200, $this->configure($client, $entete, ['connecteur' => 'externe', 'marque' => 'pax', 'ref' => 'R'])->getStatusCode());
        });
        [$client, $entete] = $this->adminSurA();
        self::assertSame(200, $this->configure($client, $entete, ['connecteur' => 'simule'])->getStatusCode(), 'En démonstration, le simulé est permis.');
    }

    /**
     * Le test d'une variable coupée : `TPE_SIMULE_AUTORISE=0`, comme en production.
     *
     * @param callable(Client, array<string, mixed>): void $test
     */
    private function sansSimulation(callable $test): void
    {
        $avant = $_ENV['TPE_SIMULE_AUTORISE'] ?? null;
        $_ENV['TPE_SIMULE_AUTORISE'] = $_SERVER['TPE_SIMULE_AUTORISE'] = '0';
        try {
            [$client, $entete] = $this->adminSurA();
            $test($client, $entete);
        } finally {
            $_ENV['TPE_SIMULE_AUTORISE'] = $_SERVER['TPE_SIMULE_AUTORISE'] = $avant;
        }
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

    /**
     * @param array<string, mixed> $entete
     * @param array<string, mixed> $corps
     */
    private function declare(Client $client, array $entete, string $vente, array $corps): ResponseInterface
    {
        return $client->request('POST', '/api/ventes/' . $vente . '/declarer-reglement', $entete + ['json' => $corps]);
    }

    private function statusOf(string $tentative): string
    {
        return (string) $this->db()->fetchOne('SELECT status FROM sale_payment_attempt WHERE id = UNHEX(:id)', ['id' => $this->hex($tentative)]);
    }
}
