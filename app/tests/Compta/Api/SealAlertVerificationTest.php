<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\DataFixtures\SocleFixtures;
use App\Platform\Nf525\SealKeyring;
use App\Securite\Service\ContexteEtablissement;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * G-3 / G-7 (ORIGINE VÉRIFICATION), CHAÎNE COMPTABLE — `GET /compta/ecritures/verifier-chaine` ouvre, met à jour et ferme l'alerte.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - Le maillon 1 (de deux) est altéré par UPDATE SQL, suivi d'un `em->clear()` (identity map) : le dernier maillon,
 *   seul lu par la porte de scellement, reste sain, donc un scellement RÉUSSIT sur une chaîne que la vérification
 *   juge rompue (témoin de l'amendement G-7).
 * - « Aucune alerte » est vrai aussi d'une alerte qui ne s'écrit jamais : chaque cas négatif a son témoin positif.
 */
final class SealAlertVerificationTest extends ComptaApiTestCase
{
    private const FOREIGN_KEY = 'cle-etrangere-alerte-verification-compta';
    private const VARIABLE = 'NF525_COMPTA_SEAL_KEY';
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

    /** @return array{0: \ApiPlatform\Symfony\Bundle\Test\Client, 1: array<string, mixed>, 2: string} une chaîne réelle de deux écritures, et l'identifiant du journal */
    private function realChain(): array
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $session = $this->ouvrirSession($client, $entete);
        $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);
        $journal = $this->em()->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);
        self::assertCount(2, $this->em()->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()]));

        return [$client, $entete, (string) $journal->getId()];
    }

    /** @param array<string, mixed> $entete */
    private function verify(\ApiPlatform\Symfony\Bundle\Test\Client $client, array $entete, string $journalId): int
    {
        return $client->request('GET', '/api/compta/ecritures/verifier-chaine', $entete + ['query' => ['journal' => $journalId]])->getStatusCode();
    }

    private function tamper(): string
    {
        $original = (string) $this->db()->fetchOne('SELECT empreinte FROM compta_ecriture_comptable WHERE numero_sequence = 1');
        self::assertNotSame('', $original, 'témoin : le maillon 1 existe');
        $this->db()->executeStatement('UPDATE compta_ecriture_comptable SET empreinte = ? WHERE numero_sequence = 1', [str_repeat('0', 64)]);
        $this->em()->clear();

        return $original;
    }

    private function repair(string $original): void
    {
        $this->db()->executeStatement('UPDATE compta_ecriture_comptable SET empreinte = ? WHERE numero_sequence = 1', [$original]);
        $this->em()->clear();
    }

    private function signLink(int $sequence, string $key): void
    {
        $fingerprint = (string) $this->db()->fetchOne('SELECT empreinte FROM compta_ecriture_comptable WHERE numero_sequence = ?', [$sequence]);
        $ring = new SealKeyring(self::VARIABLE, $key);
        $this->db()->executeStatement(
            'UPDATE compta_ecriture_comptable SET signature = ?, seal_key_id = ? WHERE numero_sequence = ?',
            [$ring->sign($fingerprint), $ring->activeKeyId(), $sequence],
        );
        $this->em()->clear();
    }

    private function draft(): EcritureComptable
    {
        $journal = $this->em()->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);

        return (new EcritureComptable())->setProfilExploitant($this->profilExploitant())->setJournal($journal);
    }

    private function handler(): ScellementEcritureHandler
    {
        $handler = static::getContainer()->get(ScellementEcritureHandler::class);
        self::assertInstanceOf(ScellementEcritureHandler::class, $handler);

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
        [$client, $entete, $journalId] = $this->realChain();
        $this->tamper();

        self::assertSame(200, $this->verify($client, $entete, $journalId), 'la réponse existante ne change pas (200, rapport)');

        self::assertFalse($client->getResponse()->toArray(false)['intacte']);
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('accounting:' . $this->idProfilExploitant() . ':' . $journalId, $alerts[0]['chain_key']);
        self::assertSame('accounting', $alerts[0]['chain_type']);
        self::assertSame('chain_broken', $alerts[0]['cause']);
        self::assertSame('verification', $alerts[0]['origin']);
        self::assertSame('open', $alerts[0]['status']);
        self::assertSame(1, (int) $alerts[0]['detection_count']);
        self::assertStringContainsString('n°1', (string) $alerts[0]['technical_detail'], 'témoin : le détail technique est dans l\'alerte');
        self::assertGreaterThan(0, $this->bells());
    }

    public function testASecondVerificationUpdatesTheSameAlertWithoutANewBell(): void
    {
        [$client, $entete, $journalId] = $this->realChain();
        $this->tamper();

        $this->verify($client, $entete, $journalId);
        $bells = $this->bells();
        self::assertGreaterThan(0, $bells, 'témoin : la première vague existe');
        $this->verify($client, $entete, $journalId);

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame(2, (int) $alerts[0]['detection_count']);
        self::assertSame($bells, $this->bells());
    }

    public function testAFixedLinkResolvesTheAlertAndANewBreakOpensANewOneWithANewBell(): void
    {
        [$client, $entete, $journalId] = $this->realChain();
        $original = $this->tamper();
        $this->verify($client, $entete, $journalId);
        $bells = $this->bells();

        $this->repair($original);
        $this->verify($client, $entete, $journalId);
        self::assertTrue($client->getResponse()->toArray(false)['intacte'], 'témoin : la chaîne réparée est intacte');
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('resolved', $alerts[0]['status']);
        self::assertNotNull($alerts[0]['resolved_at']);

        $this->tamper();
        $this->verify($client, $entete, $journalId);
        $all = $this->alerts();
        self::assertCount(2, $all, 'une NOUVELLE alerte, l\'ancienne reste résolue');
        $statuses = array_column($all, 'status');
        sort($statuses);
        self::assertSame(['open', 'resolved'], $statuses);
        self::assertGreaterThan($bells, $this->bells());
    }

    public function testASuccessfulSealLeavesTheVerificationAlertOpen(): void
    {
        [$client, $entete, $journalId] = $this->realChain();
        $this->tamper();
        $this->verify($client, $entete, $journalId);

        $draft = $this->draft();
        $this->handler()->sceller($draft);

        self::assertSame(3, $draft->getNumeroSequence(), 'témoin : le scellement a bien eu lieu');
        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('open', $alerts[0]['status']);
    }

    public function testAMixedAlertStaysOpenAfterASuccessfulSeal(): void
    {
        [$client, $entete, $journalId] = $this->realChain();
        $this->tamper();
        $this->signLink(2, self::FOREIGN_KEY);
        try {
            $this->handler()->sceller($this->draft());
            self::fail('Le scellement aurait dû être refusé.');
        } catch (\App\Platform\Nf525\SealingRefusedException) {
        }
        self::assertSame('refusal', $this->alerts()[0]['origin']);

        $this->verify($client, $entete, $journalId);
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
        [$client, $entete, $journalId] = $this->realChain();

        self::assertSame(200, $this->verify($client, $entete, $journalId));

        $body = $client->getResponse()->toArray(false);
        self::assertTrue($body['intacte']);
        self::assertSame(2, $body['nbOperations'], 'témoin : la vérification a bien porté sur la chaîne');
        self::assertSame([], $this->alerts());
        self::assertSame(0, $this->bells());
    }

    public function testARequestRefusedWithA404OpensNothing(): void
    {
        [$client, $entete, $journalId] = $this->realChain();
        $this->tamper();
        $outside = ['auth_bearer' => $entete['auth_bearer'], 'headers' => [ContexteEtablissement::HEADER => $this->idEtablissement(SocleFixtures::ETAB_B_NOM)]];

        self::assertSame(404, $this->verify($client, $outside, $journalId));
        self::assertSame([], $this->alerts(), 'une requête refusée n\'ouvre rien');

        // Témoin positif : la même chaîne, vue de son périmètre, ouvre bien l'alerte.
        $this->verify($client, $entete, $journalId);
        self::assertCount(1, $this->alerts());
    }
}
