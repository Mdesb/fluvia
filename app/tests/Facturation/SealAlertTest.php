<?php

declare(strict_types=1);

namespace App\Tests\Facturation;

use App\DataFixtures\SocleFixtures;
use App\Facturation\Entity\Facture;
use App\Facturation\Nf525\ScellementFactureHandler;
use App\Organisation\Entity\Etablissement;
use App\Platform\Nf525\SealingRefusedException;
use App\Platform\Nf525\SealKeyring;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * UN REFUS DE SCELLER LA CHAÎNE DE FACTURATION OUVRE UNE ALERTE DU BON TYPE — appelant hors transaction (le
 * handler est appelé directement, comme une commande) et appelant imbriqué (`AvoirFactureHandler`, qui scelle
 * dans un `wrapInTransaction` : l'alerte doit survivre au rollback de l'avoir).
 *
 * ── LES PIÈGES ──────────────────────────────────────────────────────────────────────────────────
 *
 * - L'alerte « présente » après un 409 n'est une preuve que si le rollback a bien eu lieu : le test imbriqué
 *   vérifie qu'aucun avoir n'existe (le témoin est l'avoir créé quand la chaîne est saine).
 * - Le handler lit le dernier maillon par l'ORM : toute réécriture SQL est suivie d'un `clear()`.
 */
final class SealAlertTest extends FacturationApiTestCase
{
    private const FOREIGN_KEY = 'cle-etrangere-alerte-facturation';
    private const VARIABLE = 'NF525_FACTURATION_SEAL_KEY';

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

    /** @return array{0: list<string>, 1: array<string, mixed>, 2: \ApiPlatform\Symfony\Bundle\Test\Client} identifiants des factures émises */
    private function issued(int $count): array
    {
        [$client, $entete] = $this->adminSurA();
        $client->disableReboot();
        $ids = [];
        for ($i = 0; $i < $count; ++$i) {
            $draft = $client->request('POST', '/api/factures', $entete + ['json' => $this->corpsFactureDirecte(100.0 + $i)])->toArray();
            $ids[] = (string) $client->request('POST', '/api/factures/' . $draft['id'] . '/emettre', $entete)->toArray()['id'];
        }

        return [$ids, $entete, $client];
    }

    private function resign(string $id, string $key, ?string $signature = null): void
    {
        $fingerprint = (string) $this->db()->fetchOne('SELECT empreinte FROM facturation_facture WHERE id = ?', [Uuid::fromString($id)->toBinary()]);
        $ring = new SealKeyring(self::VARIABLE, $key);
        $this->db()->executeStatement(
            'UPDATE facturation_facture SET seal_key_id = ?, signature = ? WHERE id = ?',
            [$ring->activeKeyId(), $signature ?? $ring->sign($fingerprint), Uuid::fromString($id)->toBinary()],
        );
        // Le handler lit le dernier maillon par l'ORM : sans `clear()`, il verrait la carte d'identité d'avant l'UPDATE.
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
        return $this->db()->fetchAllAssociative('SELECT * FROM platform_nf525_alert');
    }

    private function refuse(ScellementFactureHandler $handler, Facture $draft): SealingRefusedException
    {
        try {
            $handler->sceller($draft);
        } catch (SealingRefusedException $e) {
            return $e;
        }
        self::fail('Le scellement aurait dû être refusé.');
    }

    public function testAnUnknownKeyOpensAnInvoicingAlertLinkedToBothEstablishmentsOfTheProfile(): void
    {
        [$ids] = $this->issued(2);
        $profile = $this->profilExploitant();
        $profile->setEtablissementsRattaches([$this->entite(Etablissement::class, ['nom' => SocleFixtures::ETAB_B_NOM])]);
        $this->em()->flush();
        $this->resign($ids[1], self::FOREIGN_KEY);

        $e = $this->refuse($this->handler(), $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('invoicing', $alerts[0]['chain_type']);
        self::assertSame('unknown_key', $alerts[0]['cause']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $e->getMessage());
        self::assertStringContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $e->technicalDetail(), 'témoin : le détail technique porte l\'identifiant');
        self::assertStringNotContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $e->getMessage());
        self::assertStringNotContainsString('NF525_', $e->getMessage());
        self::assertSame(2, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment WHERE alert_id = ?', [$alerts[0]['id']]));
        foreach ([SocleFixtures::ETAB_A_NOM, SocleFixtures::ETAB_B_NOM] as $name) {
            $id = $this->entite(Etablissement::class, ['nom' => $name])->getId();
            self::assertGreaterThan(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_notification WHERE source = ? AND etablissement_id = ?', ['nf525.chain_blocked', $id->toBinary()]), $name);
        }
    }

    public function testAnInvalidSignatureOpensTheAlertWithItsCause(): void
    {
        [$ids] = $this->issued(2);
        $this->resign($ids[1], $this->activeKey(), str_repeat('f', 64));

        $e = $this->refuse($this->handler(), $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('invalid_signature', $alerts[0]['cause']);
        self::assertStringContainsString((string) $alerts[0]['reference'], $e->getMessage());
    }

    public function testAKeyRegressionOpensItsAlertAndTheMessageKeepsNoTechnicalDetail(): void
    {
        [$ids] = $this->issued(2);
        $this->resign($ids[1], self::FOREIGN_KEY);
        $recorder = (new \ReflectionProperty(ScellementFactureHandler::class, 'alerts'))->getValue($this->handler());
        $real = $this->handler();
        $handler = new ScellementFactureHandler(
            $this->em(),
            new SealKeyring(self::VARIABLE, $this->activeKey(), self::FOREIGN_KEY),
            (new \ReflectionProperty(ScellementFactureHandler::class, 'instantaneEmetteur'))->getValue($real),
            $recorder,
        );

        $e = $this->refuse($handler, $this->draft());

        $alerts = $this->alerts();
        self::assertCount(1, $alerts);
        self::assertSame('key_regression', $alerts[0]['cause']);
        self::assertStringContainsString('NEUVE', $e->technicalDetail(), 'témoin');
        self::assertStringNotContainsString('NEUVE', $e->getMessage());
        self::assertStringNotContainsString(SealKeyring::keyIdOf(self::FOREIGN_KEY), $e->getMessage());
    }

    public function testNestedCallerCreditNoteRefusedRollsBackButTheAlertSurvives(): void
    {
        [$ids, $entete, $client] = $this->issued(2);
        $this->resign($ids[1], self::FOREIGN_KEY);

        $client->request('POST', '/api/factures/' . $ids[0] . '/avoir', $entete);

        self::assertResponseStatusCodeSame(409);
        $detail = (string) $client->getResponse()->toArray(false)['detail'];
        $alerts = $this->alerts();
        self::assertCount(1, $alerts, 'l\'alerte survit au rollback de l\'avoir');
        self::assertStringContainsString((string) $alerts[0]['reference'], $detail);
        self::assertSame(0, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM facturation_facture WHERE nature = ?', ['avoir']), 'rollback prouvé : aucun avoir');
    }

    public function testControlAHealthyChainIssuesTheCreditNoteWithoutAlert(): void
    {
        [$ids, $entete, $client] = $this->issued(2);

        $client->request('POST', '/api/factures/' . $ids[0] . '/avoir', $entete);

        self::assertResponseIsSuccessful();
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM facturation_facture WHERE nature = ?', ['avoir']));
        self::assertSame([], $this->alerts());
    }

    public function testWithNullTheHandlerWritesNoAlertAndTheRefusalHasNoReference(): void
    {
        [$ids] = $this->issued(2);
        $this->resign($ids[1], self::FOREIGN_KEY);
        $real = $this->handler();
        $handler = new ScellementFactureHandler(
            $this->em(),
            new SealKeyring(self::VARIABLE, $this->activeKey()),
            (new \ReflectionProperty(ScellementFactureHandler::class, 'instantaneEmetteur'))->getValue($real),
        );

        $e = $this->refuse($handler, $this->draft());

        self::assertSame([], $this->alerts());
        self::assertNull($e->reference());
        // Témoin : le même refus, avec le service du conteneur, écrit bien une alerte.
        $this->refuse($real, $this->draft());
        self::assertCount(1, $this->alerts());
    }
}
