<?php

declare(strict_types=1);

namespace App\Tests\Facturation\Api;

use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Platform\Nf525\SealKeyring;
use App\Platform\Nf525\SealingRefusedException;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Facturation\FacturationApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-3 / G-7 (ORIGINE VÉRIFICATION), CHAÎNE DE FACTURATION — `GET /factures/verifier-chaine` ouvre, met à jour et ferme l'alerte.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - La facture n° 1 (de deux) est altérée par UPDATE SQL, suivi d'un `em->clear()` (identity map) : la dernière,
 *   seule lue par la porte de scellement, reste saine, donc un scellement RÉUSSIT sur une chaîne que la vérification
 *   juge rompue (témoin de l'amendement G-7).
 * - « Aucune alerte » est vrai aussi d'une alerte qui ne s'écrit jamais : chaque cas négatif a son témoin positif.
 */
final class SealAlertVerificationTest extends FacturationApiTestCase
{
    private const FOREIGN_KEY = 'cle-etrangere-alerte-verification-facturation';
    private const VARIABLE = 'NF525_FACTURATION_SEAL_KEY';
    private const BELL_SOURCE = 'nf525.chain_blocked';

    private function em(): EntityManagerInterface
    {
        /** @var EntityManagerInterface $em */
        $em = static::getContainer()->get('doctrine')->getManager();

        return $em;
    }

    private function db(): Connection
    {
        return $this->em()->getConnection();
    }

    private function activeKey(): string
    {
        $key = $_SERVER[self::VARIABLE] ?? $_ENV[self::VARIABLE] ?? getenv(self::VARIABLE);
        self::assertIsString($key);

        return $key;
    }

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>} */
    private function issuedChain(): array
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        for ($i = 0; $i < 2; ++$i) {
            $draft = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0 + $i)])->toArray();
            $client->request('POST', '/api/factures/' . $draft['id'] . '/emettre', $entete);
            self::assertResponseIsSuccessful();
        }

        return [$client, $entete];
    }

    /** @param array<string, mixed> $entete */
    private function verify(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete): int
    {
        return $client->request('GET', '/api/factures/verifier-chaine', $entete)->getStatusCode();
    }

    private function tamper(): string
    {
        $original = (string) $this->db()->fetchOne('SELECT empreinte FROM facturation_facture WHERE numero_sequence = 1');
        self::assertNotSame('', $original, 'témoin : la facture 1 existe');
        $this->db()->executeStatement('UPDATE facturation_facture SET empreinte = ? WHERE numero_sequence = 1', [str_repeat('0', 64)]);
        $this->em()->clear();

        return $original;
    }

    private function repair(string $original): void
    {
        $this->db()->executeStatement('UPDATE facturation_facture SET empreinte = ? WHERE numero_sequence = 1', [$original]);
        $this->em()->clear();
    }

    private function signLink(int $sequence, string $key): void
    {
        $fingerprint = (string) $this->db()->fetchOne('SELECT empreinte FROM facturation_facture WHERE numero_sequence = ?', [$sequence]);
        $ring = new SealKeyring(self::VARIABLE, $key);
        $this->db()->executeStatement(
            'UPDATE facturation_facture SET signature = ?, seal_key_id = ? WHERE numero_sequence = ?',
            [$ring->sign($fingerprint), $ring->activeKeyId(), $sequence],
        );
        $this->em()->clear();
    }

    private function draft(): Facture
    {
        [$client, $entete] = $this->adminSurA();
        $id = (string) $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(999.0)])->toArray()['id'];
        $this->em()->clear();
        $facture = $this->em()->getRepository(Facture::class)->find(Uuid::fromString($id));
        self::assertNotNull($facture);

        return $facture;
    }

    private function handler(): ScellementFactureHandler
    {
        $handler = static::getContainer()->get(ScellementFactureHandler::class);
        self::assertInstanceOf(ScellementFactureHandler::class, $handler);

        return $handler;
    }

    /** @return list<array<string, mixed>> */
    private function alerts(): array
    {
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert ORDER BY first_event_at');
    }

    private function bells(): int
    {
        return (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification WHERE source = ?', [self::BELL_SOURCE]);
    }

    public function testABrokenChainOpensTheAlertAndRingsTheBellWhileTheResponseIsUnchanged(): void
    {
        [$client, $entete] = $this->issuedChain();
        $this->tamper();

        self::assertSame(200, $this->verify($client, $entete), 'la réponse existante ne change pas (200, rapport)');

        self::assertFalse($client->getResponse()->toArray(false)['intacte']);
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('invoicing:' . $this->idProfilExploitant(), $alerts[0]['chain_key']);
        self::assertSame('chain_broken', $alerts[0]['cause']);
        self::assertSame('verification', $alerts[0]['origin']);
        self::assertSame('open', $alerts[0]['status']);
        self::assertSame(1, (int) $alerts[0]['detection_count']);
        self::assertStringContainsString('n°1', (string) $alerts[0]['technical_detail'], 'témoin : le détail technique est dans l\'alerte');
        self::assertGreaterThan(0, $this->bells());
    }

    public function testASecondVerificationUpdatesTheSameAlertWithoutANewBell(): void
    {
        [$client, $entete] = $this->issuedChain();
        $this->tamper();

        $this->verify($client, $entete);
        $bells = $this->bells();
        self::assertGreaterThan(0, $bells, 'témoin : la première vague existe');
        $this->verify($client, $entete);

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame(2, (int) $alerts[0]['detection_count']);
        self::assertSame($bells, $this->bells());
    }

    public function testAFixedLinkResolvesTheAlertAndANewBreakOpensANewOneWithANewBell(): void
    {
        [$client, $entete] = $this->issuedChain();
        $original = $this->tamper();
        $this->verify($client, $entete);
        $bells = $this->bells();

        $this->repair($original);
        $this->verify($client, $entete);
        self::assertTrue($client->getResponse()->toArray(false)['intacte'], 'témoin : la chaîne réparée est intacte');
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('resolved', $alerts[0]['status']);
        self::assertNotNull($alerts[0]['resolved_at']);

        $this->tamper();
        $this->verify($client, $entete);
        $all = $this->alerts();
        self::assertCount(2, $all, 'une NOUVELLE alerte, l\'ancienne reste résolue');
        $statuses = array_column($all, 'status');
        sort($statuses);
        self::assertSame(['open', 'resolved'], $statuses);
        self::assertGreaterThan($bells, $this->bells());
    }

    public function testASuccessfulSealLeavesTheVerificationAlertOpen(): void
    {
        [$client, $entete] = $this->issuedChain();
        $this->tamper();
        $this->verify($client, $entete);

        $draft = $this->draft();
        $this->handler()->sceller($draft);

        self::assertSame(3, $draft->getNumeroSequence(), 'témoin : le scellement a bien eu lieu');
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('open', $alerts[0]['status']);
    }

    public function testAMixedAlertStaysOpenAfterASuccessfulSeal(): void
    {
        [$client, $entete] = $this->issuedChain();
        $this->tamper();
        $this->signLink(2, self::FOREIGN_KEY);
        try {
            $this->handler()->sceller($this->draft());
            self::fail('Le scellement aurait dû être refusé.');
        } catch (SealingRefusedException) {
        }
        self::assertSame('refusal', $this->alerts()[0]['origin']);

        $this->verify($client, $entete);
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('verification', $alerts[0]['origin'], 'la règle la plus stricte');

        $this->signLink(2, $this->activeKey());
        $draft = $this->draft();
        $this->handler()->sceller($draft);

        self::assertSame(3, $draft->getNumeroSequence(), 'témoin : le scellement a bien eu lieu');
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('open', $alerts[0]['status']);
    }

    public function testControlAnIntactChainOpensNoAlert(): void
    {
        [$client, $entete] = $this->issuedChain();

        self::assertSame(200, $this->verify($client, $entete));

        $body = $client->getResponse()->toArray(false);
        self::assertTrue($body['intacte']);
        self::assertSame(2, $body['nbDocuments'], 'témoin : la vérification a bien porté sur la chaîne');
        self::assertSame([], $this->alerts());
        self::assertSame(0, $this->bells());
    }

    public function testARequestRefusedWithA404OpensNothing(): void
    {
        [$client, $entete] = $this->issuedChain();
        $this->tamper();
        $outside = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];

        self::assertSame(404, $client->request('GET', '/api/factures/verifier-chaine?profilExploitant=' . $this->idProfilExploitant(), $outside)->getStatusCode());
        self::assertSame([], $this->alerts(), 'une requête refusée n\'ouvre rien');

        // Témoin positif : la même chaîne, vue de son périmètre, ouvre bien l'alerte.
        $this->verify($client, $entete);
        self::assertCount(1, $this->alerts());
    }
}
