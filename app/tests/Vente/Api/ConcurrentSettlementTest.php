<?php

declare(strict_types=1);

namespace App\Tests\Vente\Api;

use App\Tests\Crm\CrmApiTestCase;
use App\Tests\Vente\Support\SettlementScenarios;
use Symfony\Component\Uid\Uuid;

/**
 * DEUX RÈGLEMENTS RÉELLEMENT CONCURRENTS SUR UNE MÊME VENTE : UN SEUL DÉBIT (G-3 du ticket opposable).
 *
 * Le premier règlement part dans un AUTRE processus PHP, à travers le noyau complet, sur sa propre
 * connexion — une seconde requête php-fpm. Il s'arrête au terminal (ou juste après le débit du
 * porte-monnaie) tant que le test ne l'a pas libéré ; le second part pendant ce temps, de ce processus.
 *
 * Avant ce lot, la garde de rejeu cherchait un règlement déjà ÉCRIT : pendant que le premier attendait
 * son terminal, rien ne l'était encore, et le second passait — terminal ou porte-monnaie sollicité
 * deux fois, et le second tombait en 500 sur l'index unique, après son débit.
 */
final class ConcurrentSettlementTest extends CrmApiTestCase
{
    use SettlementScenarios;

    /** Deux intentions (deux clés : double clic, deux onglets) sur une vente de 45,00 : la seconde reçoit « en cours ». */
    public function testTwoCardPaymentsOnA45SaleChargeOnce(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        $this->waitUntilHeld($premier, 'terminal');

        $second = $this->pay($client, $entete, $vente, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        $issueSeconde = [$second->getStatusCode(), $second->toArray(false)['code'] ?? null];
        $issuePremiere = $this->release($premier);

        self::assertSame([409, 'payment_in_progress'], $issueSeconde);
        self::assertSame(201, $issuePremiere['status']);
        self::assertSame(1, $this->paymentCount($vente), 'Un seul débit. Deux = 90,00 encaissés sur une vente de 45,00.');
        self::assertSame('0.00', $client->request('GET', '/api/ventes/' . $vente, $entete)->toArray()['resteAPayer']);
    }

    /**
     * La même clé pendant que la première attend son terminal : « en cours », jamais un second appel.
     * Le second appel force un REFUS : sollicité, le terminal aurait répondu 200 « refusé ».
     */
    public function testTheSameKeyNeverReachesTheTerminalTwice(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $corps = ['moyen' => 'cb', 'montant' => '20.00', 'cleIdempotence' => (string) Uuid::v4()];
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, $corps);
        $this->waitUntilHeld($premier, 'terminal');

        $second = $this->pay($client, $entete, $vente, $corps, 'refuse');
        $issueSeconde = [$second->getStatusCode(), $second->toArray(false)['code'] ?? null];
        $issuePremiere = $this->release($premier);

        self::assertSame([409, 'payment_in_progress'], $issueSeconde);
        self::assertSame(201, $issuePremiere['status']);
        self::assertSame(1, $this->paymentCount($vente));
    }

    /** La même clé pendant que le premier appel vient de débiter le porte-monnaie : un seul débit, le solde en témoigne. */
    public function testTheSameKeyDebitsTheWalletOnce(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->creerVenteAvecClient($client, $entete, '/api/clients/' . $this->idPayeur(), 2)['id']; // 9,90
        $corps = ['moyen' => 'pmv', 'montant' => '4.00', 'cleIdempotence' => (string) Uuid::v4()];
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, $corps);
        $this->waitUntilHeld($premier, 'wallet');

        $second = $this->pay($client, $entete, $vente, $corps);
        $issueSeconde = [$second->getStatusCode(), $second->toArray(false)['code'] ?? null];
        $issuePremiere = $this->release($premier);

        self::assertSame('46.00', $this->balance($client, $entete), 'Un seul débit de 4,00 sur 50,00.');
        self::assertSame([409, 'payment_in_progress'], $issueSeconde);
        self::assertSame(201, $issuePremiere['status']);
        self::assertSame(1, $this->paymentCount($vente));
    }

    /**
     * Le terminal tarde au-delà de 120 s : une autre demande juge la tentative « unresolved » et ne passe
     * pas (la carte a pu être débitée). Quand le terminal répond enfin, son issue s'écrit : personne n'a
     * pu encaisser entre-temps, la vente était tenue.
     */
    public function testALateTerminalAnswerIsStillWrittenAndNothingPassedMeanwhile(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle]);
        $this->waitUntilHeld($premier, 'terminal');
        $this->db()->executeStatement(
            'UPDATE sale_payment_attempt SET started_at = :d WHERE idempotency_key = UNHEX(:k)',
            ['d' => (new \DateTimeImmutable('-121 seconds'))->format('Y-m-d H:i:s'), 'k' => $this->hex($cle)],
        );

        $second = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        $issueSeconde = [$second->getStatusCode(), $second->toArray(false)['code'] ?? null];
        $pendant = $this->attemptOf($cle)['status'] ?? null;
        $issuePremiere = $this->release($premier);

        self::assertSame([409, 'payment_outcome_unknown'], $issueSeconde);
        self::assertSame('unresolved', $pendant);
        self::assertSame(201, $issuePremiere['status'], (string) json_encode($issuePremiere['body']));
        self::assertSame(['accepted', 0], [$this->attemptOf($cle)['status'] ?? null, (int) ($this->attemptOf($cle)['holds_sale'] ?? -1)]);
        self::assertSame(1, $this->paymentCount($vente));
    }

    /** Le même retard, terminé par un REFUS : aucun argent n'a bougé, la tentative est close et la vente libérée. */
    public function testALateTerminalRefusalFreesTheSale(): void
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], 'refuse');
        $this->waitUntilHeld($premier, 'terminal');
        $this->db()->executeStatement(
            'UPDATE sale_payment_attempt SET started_at = :d WHERE idempotency_key = UNHEX(:k)',
            ['d' => (new \DateTimeImmutable('-121 seconds'))->format('Y-m-d H:i:s'), 'k' => $this->hex($cle)],
        );

        $pendant = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        $issuePendant = [$pendant->getStatusCode(), $pendant->toArray(false)['code'] ?? null];
        $issuePremiere = $this->release($premier);

        self::assertSame([409, 'payment_outcome_unknown'], $issuePendant);
        self::assertSame([200, 'refuse'], [$issuePremiere['status'], $issuePremiere['body']['statutTPE'] ?? null]);
        self::assertSame(['refused', 0], [$this->attemptOf($cle)['status'] ?? null, (int) ($this->attemptOf($cle)['holds_sale'] ?? -1)]);
        $apres = $this->pay($client, $entete, $vente, ['moyen' => 'especes', 'montant' => '45.00', 'cleIdempotence' => (string) Uuid::v4()]);
        self::assertSame(201, $apres->getStatusCode(), 'La vente est libre : l\'espèce passe.');
    }

    /**
     * Le caissier déclare « accepté » pendant que le terminal tarde (lot 3) : sa déclaration tient, et
     * l'acceptation tardive n'écrit pas un second règlement — l'index unique de la clé l'en empêche.
     */
    public function testADeclaredAcceptanceWinsOverALateTerminalAnswer(): void
    {
        [$apres, $tardive, $tentative] = $this->declareWhileTheTerminalLags('accepte');

        self::assertSame(201, $apres->getStatusCode(), $apres->getContent(false));
        self::assertSame([409, 'payment_outcome_known'], [$tardive['status'], $tardive['body']['code'] ?? null]);
        self::assertSame('declared_accepted', $tentative['status']);
        self::assertSame(1, $tentative['reglements'], 'Un seul règlement : le déclaré.');
    }

    /**
     * Le caissier déclare « non passé » pendant que le terminal tarde, et le terminal accepte quand
     * même : la déclaration tient — rien n'est écrit —, mais la référence de l'acceptation reste sur la
     * tentative. Sans elle, un client débité sans règlement ne laisserait aucune trace.
     */
    public function testALateAcceptanceAfterANotProcessedDeclarationLeavesItsReference(): void
    {
        [$apres, $tardive, $tentative] = $this->declareWhileTheTerminalLags('non_passe');

        self::assertSame(200, $apres->getStatusCode(), $apres->getContent(false));
        self::assertSame([409, 'payment_outcome_known'], [$tardive['status'], $tardive['body']['code'] ?? null]);
        self::assertSame(['declared_not_processed', 0], [$tentative['status'], $tentative['reglements']]);
        self::assertStringContainsString('le terminal a répondu « accepte » (réf.', (string) $tentative['raison']);
        self::assertStringContainsString('Sans issue après 120 s', (string) $tentative['raison'], 'La raison d\'origine est gardée derrière la note.');
    }

    /**
     * Le caissier déclare « accepté » et le terminal, en retard, REFUSE : la déclaration tient (un
     * règlement), mais le refus reste écrit sur la tentative — un règlement sans débit se rapproche.
     */
    public function testALateRefusalAfterAnAcceptedDeclarationLeavesATrace(): void
    {
        [$apres, $tardive, $tentative] = $this->declareWhileTheTerminalLags('accepte', 'refuse');

        self::assertSame(201, $apres->getStatusCode(), $apres->getContent(false));
        self::assertSame([409, 'payment_outcome_known'], [$tardive['status'], $tardive['body']['code'] ?? null]);
        self::assertSame(['declared_accepted', 1], [$tentative['status'], $tentative['reglements']]);
        self::assertStringContainsString('le terminal a répondu « refuse »', (string) $tentative['raison']);
    }

    /**
     * Une carte part dans un autre processus et s'arrête au terminal (qui acceptera) ; la tentative est
     * vieillie au-delà de 120 s, puis le caissier déclare ; enfin le terminal répond.
     *
     * @return array{0: \Symfony\Contracts\HttpClient\ResponseInterface, 1: array{status: int, body: array<string, mixed>}, 2: array{status: string, raison: ?string, reglements: int}}
     */
    private function declareWhileTheTerminalLags(string $issue, string $tpe = ''): array
    {
        [$client, $entete, $idA] = $this->adminSurA();
        $vente = $this->cardSale($client, $entete);
        $cle = (string) Uuid::v4();
        $premier = $this->settleInOtherProcess($vente, $entete['auth_bearer'], $idA, ['moyen' => 'cb', 'montant' => '45.00', 'cleIdempotence' => $cle], $tpe);
        $this->waitUntilHeld($premier, 'terminal');
        $this->db()->executeStatement(
            'UPDATE sale_payment_attempt SET started_at = :d WHERE idempotency_key = UNHEX(:k)',
            ['d' => (new \DateTimeImmutable('-121 seconds'))->format('Y-m-d H:i:s'), 'k' => $this->hex($cle)],
        );
        $id = (string) $this->db()->fetchOne('SELECT HEX(id) FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k)', ['k' => $this->hex($cle)]);

        $apres = $client->request('POST', '/api/ventes/' . $vente . '/declarer-reglement', $entete + ['json' => [
            'tentative' => Uuid::fromBinary((string) hex2bin($id))->toRfc4122(), 'issue' => $issue, 'referenceCarte' => 'CB-LUE-AU-TERMINAL',
        ]]);
        $apres->getStatusCode();
        $tardive = $this->release($premier);
        $ligne = $this->db()->fetchAssociative('SELECT status, failure_reason FROM sale_payment_attempt WHERE idempotency_key = UNHEX(:k)', ['k' => $this->hex($cle)]);

        return [$apres, $tardive, ['status' => (string) $ligne['status'], 'raison' => $ligne['failure_reason'], 'reglements' => $this->paymentCount($vente)]];
    }

    /**
     * @param array<string, mixed> $corps
     *
     * @return array{0: resource, 1: string}
     */
    private function settleInOtherProcess(string $vente, string $jeton, string $idA, array $corps, string $tpe = ''): array
    {
        $dossier = sys_get_temp_dir() . '/settle-' . bin2hex(random_bytes(6));
        mkdir($dossier);
        // Le journal va dans un FICHIER : un tuyau plein que personne ne lit bloquerait l'enfant.
        $processus = proc_open(
            [\PHP_BINARY, '-d', 'memory_limit=1G', \dirname(__DIR__) . '/Support/settle-in-other-process.php', $dossier, $vente, $jeton, $idA, (string) json_encode($corps), $tpe],
            [1 => ['file', $dossier . '/out.log', 'w'], 2 => ['file', $dossier . '/err.log', 'w']],
            $tuyaux,
        );
        self::assertIsResource($processus, 'L\'autre processus n\'a pas démarré.');

        return [$processus, $dossier];
    }

    /** @param array{0: resource, 1: string} $autre */
    private function waitUntilHeld(array $autre, string $etape): void
    {
        [$processus, $dossier] = $autre;
        $limite = microtime(true) + 60;
        while (!is_file($dossier . '/' . $etape . '.reached')) {
            if (!proc_get_status($processus)['running'] || microtime(true) > $limite) {
                touch($dossier . '/release');
                proc_close($processus);
                self::fail(sprintf(
                    'L\'autre processus n\'a pas atteint « %s » : %s %s',
                    $etape,
                    (string) @file_get_contents($dossier . '/response.json'),
                    substr((string) @file_get_contents($dossier . '/err.log'), -3000),
                ));
            }
            usleep(20_000);
        }
    }

    /**
     * @param array{0: resource, 1: string} $autre
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    private function release(array $autre): array
    {
        [$processus, $dossier] = $autre;
        touch($dossier . '/release');
        $code = proc_close($processus);
        $erreurs = substr((string) @file_get_contents($dossier . '/err.log'), -3000);
        $reponse = json_decode((string) @file_get_contents($dossier . '/response.json'), true);
        array_map('unlink', (array) glob($dossier . '/*'));
        rmdir($dossier);
        self::assertSame(0, $code, 'L\'autre processus a échoué : ' . $erreurs);
        self::assertIsArray($reponse, 'L\'autre processus n\'a rien répondu : ' . $erreurs);

        return ['status' => (int) $reponse['status'], 'body' => (array) json_decode((string) $reponse['body'], true)];
    }
}
