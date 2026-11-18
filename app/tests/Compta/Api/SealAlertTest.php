<?php

declare(strict_types=1);

namespace App\Tests\Compta\Api;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\Journal;
use App\Compta\Nf525\ScellementEcritureHandler;
use App\DataFixtures\SocleFixtures;
use App\Platform\Nf525\Alert\SealAlertRecorder;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UN REFUS DE SCELLER LA CHAÎNE COMPTABLE OUVRE UNE ALERTE DU BON TYPE — appelant hors transaction (le handler
 * est appelé sans `wrapInTransaction`, comme une commande), handler construit par le conteneur.
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - « Le message est propre » est vrai d'un message vide : chaque test relit `technicalDetail()` (témoin
 *   positif, il porte bien l'identifiant) avant de dire que `getMessage()` ne l'a pas.
 * - Avec `null` le handler ne touche pas à l'alerte : le témoin est le même scénario avec le vrai service.
 */
final class SealAlertTest extends ComptaApiTestCase
{
    private const FOREIGN_KEY = 'cle-etrangere-alerte-compta';
    private const VARIABLE = 'NF525_COMPTA_SEAL_KEY';

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

    /** @return array{0: EcritureComptable, 1: list<EcritureComptable>} un brouillon prêt à sceller, et la chaîne réelle du journal des ventes */
    private function draftOnARealChain(int $length): array
    {
        [$client, $entete] = $this->adminSurA();
        $session = $this->ouvrirSession($client, $entete);
        for ($i = 0; $i < $length; ++$i) {
            $this->creerVenteValidee($client, $entete, quantite: 1, sessionId: $session['id']);
        }
        $client->request('POST', '/api/compta/ecritures/generer', $entete + [
            'json' => ['profilExploitant' => '/api/profil_exploitants/' . $this->idProfilExploitant()],
        ]);
        $journal = $this->em()->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);
        $chain = $this->em()->getRepository(EcritureComptable::class)->findBy(['journal' => $journal->getId()], ['numeroSequence' => 'ASC']);
        self::assertCount($length, $chain);

        return [$this->draft(), array_values($chain)];
    }

    private function draft(): EcritureComptable
    {
        $journal = $this->em()->getRepository(Journal::class)->findOneBy(['code' => 'VTE']);
        self::assertNotNull($journal);

        return (new EcritureComptable())->setProfilExploitant($this->profilExploitant())->setJournal($journal);
    }

    private function resign(EcritureComptable $link, string $key, ?string $signature = null): void
    {
        $ring = new SealKeyring(self::VARIABLE, $key);
        $this->db()->executeStatement(
            'UPDATE compta_ecriture_comptable SET seal_key_id = ?, signature = ? WHERE id = ?',
            [$ring->activeKeyId(), $signature ?? $ring->sign($link->getEmpreinte()), Uuid::fromString((string) $link->getId())->toBinary()],
        );
        // Le handler lit le dernier maillon par l'ORM : sans `clear()`, il verrait la carte d'identité d'avant l'UPDATE.
        $this->em()->clear();
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
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert');
    }

    private function refuse(ScellementEcritureHandler $handler, EcritureComptable $draft): SealingRefusedException
    {
        try {
            $handler->sceller($draft);
        } catch (SealingRefusedException $e) {
            return $e;
        }
        self::fail('Le scellement aurait dû être refusé.');
    }

    private function assertCleanMessage(SealingRefusedException $e, string ...$secrets): void
    {
        foreach ($secrets as $secret) {
            self::assertStringContainsString($secret, $e->technicalDetail(), 'témoin : le détail technique porte bien l\'identifiant');
            self::assertStringNotContainsString($secret, $e->getMessage());
        }
        self::assertStringNotContainsString('NF525_', $e->getMessage());
        self::assertDoesNotMatchRegularExpression('/[0-9a-f]{8}-[0-9a-f]{4}-/i', $e->getMessage());
    }

    public function testAnUnknownKeyOpensAnAccountingAlertLinkedToTheEstablishmentsAndRingsTheBell(): void
    {
        [, $chain] = $this->draftOnARealChain(2);
        $profile = $this->profilExploitant();
        $profile->setEtablissementsRattaches([$this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
        $this->em()->flush();
        $this->resign($chain[1], self::FOREIGN_KEY);

        $e = $this->refuse($this->handler(), $draft = $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('accounting', $alerts[0]['chain_type']);
        self::assertSame('unknown_key', $alerts[0]['cause']);
        self::assertSame('refusal', $alerts[0]['origin']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $e->getMessage());
        $this->assertCleanMessage($e, SealKeyring::keyIdOf(self::FOREIGN_KEY));
        self::assertSame(0, $draft->getNumeroSequence(), 'rien n\'est scellé');
        // Profil à deux établissements : les deux liaisons et la cloche des deux.
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment WHERE alert_id = ?', [$alerts[0]['id']]));
        foreach ([SocleFixtures::ETAB_A_NOM, SocleFixtures::ETAB_B_NOM] as $name) {
            $id = $this->entite(\App\Organisation\Entity\Etablissement::class, ['nom' => $name])->getId();
            self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification WHERE source = ? AND etablissement_id = ?', ['nf525.chain_blocked', $id->toBinary()]), $name);
        }
    }

    public function testAnInvalidSignatureOpensTheAlertWithItsCause(): void
    {
        [, $chain] = $this->draftOnARealChain(2);
        $this->resign($chain[1], $this->activeKey(), str_repeat('f', 64));

        $e = $this->refuse($this->handler(), $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('invalid_signature', $alerts[0]['cause']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $e->getMessage());
        self::assertStringNotContainsString('NF525_', $e->getMessage());
    }

    public function testAKeyRegressionAndAnUnreadableOrderOpenTheirAlert(): void
    {
        [, $chain] = $this->draftOnARealChain(2);
        $this->resign($chain[1], self::FOREIGN_KEY);
        $recorder = (new \ReflectionProperty(ScellementEcritureHandler::class, 'alerts'))->getValue($this->handler());
        self::assertInstanceOf(SealAlertRecorder::class, $recorder);
        // La clé étrangère est retirée mais connue : la signature se vérifie, seul l'ORDRE refuse (A -> B, A actif).
        $ring = new SealKeyring(self::VARIABLE, $this->activeKey(), self::FOREIGN_KEY);
        $handler = new ScellementEcritureHandler($this->em(), $ring, $recorder);

        $e = $this->refuse($handler, $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('key_regression', $alerts[0]['cause']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $e->getMessage());
        self::assertStringNotContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $e->getMessage());
        self::assertStringContainsString('NEUVE', $e->technicalDetail(), 'témoin : le détail technique, lui, est conservé');
    }

    public function testWithNullTheHandlerWritesNoAlertAndTheRefusalHasNoReference(): void
    {
        [, $chain] = $this->draftOnARealChain(2);
        $this->resign($chain[1], self::FOREIGN_KEY);
        $handler = new ScellementEcritureHandler($this->em(), new SealKeyring(self::VARIABLE, $this->activeKey()));

        $e = $this->refuse($handler, $this->draft());

        self::assertSame([], $this->alerts());
        self::assertStringNotContainsString('Référence', $e->getMessage());
        self::assertNull($e->reference());
        // Témoin : le même refus, avec le service du conteneur, écrit bien une alerte.
        $this->refuse($this->handler(), $this->draft());
        self::assertCount(1, $this->alerts());
    }

    public function testAHealthyChainSealsWithoutAnyAlert(): void
    {
        [$draft] = $this->draftOnARealChain(2);

        $this->handler()->sceller($draft);

        self::assertSame(3, $draft->getNumeroSequence());
        self::assertSame([], $this->alerts());
    }
}
