<?php

declare(strict_types=1);

namespace App\Tests\Platform\Integration;

use App\DataFixtures\SocleFixtures;
use App\Organisation\Entity\Etablissement;
use App\Platform\Entity\Nf525Alert;
use App\Platform\Nf525\SealAlertOrigin;
use App\Platform\Nf525\SealAlertStatus;
use App\Platform\Nf525\SealChainType;
use App\Platform\Nf525\SealRefusalCause;
use App\Tests\SocleApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\NotNullConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * LA TABLE D'ALERTE NF525 GARANTIT-ELLE, ELLE-MÊME, « UNE ALERTE OUVERTE PAR CHAÎNE » ?
 *
 * ── LE PIÈGE ────────────────────────────────────────────────────────────────────────────────────
 *
 * L'unicité repose sur `open_chain_key`, colonne générée virtuelle (NULL hors `status = 'open'`).
 * MariaDB laisse passer autant de NULL qu'on veut sous un index UNIQUE : si la colonne valait NULL
 * pour une alerte ouverte, les doublons passeraient sans bruit. D'où les tests « jamais NULL pour une
 * ouverte » et « `status`/`chain_key` NULL refusés ».
 *
 * ⚠ La suite construit son schéma depuis les métadonnées ORM, pas depuis les migrations : sans
 * `Nf525AlertSchemaListener`, l'unicité n'existerait que sur le papier de la migration. Le premier
 * test le constate dans `information_schema`.
 *
 * Les lignes sont écrites en SQL (paramétré) pour pouvoir tenter des valeurs que l'entité refuserait.
 */
final class Nf525AlertSchemaTest extends SocleApiTestCase
{
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

    /** @param array<string, mixed> $override */
    private function insertAlert(array $override = []): string
    {
        $id = Uuid::v4();
        $row = $override + [
            'id' => $id->toBinary(),
            'reference' => 'NF525-'.strtoupper(bin2hex(random_bytes(4))),
            'chain_type' => 'sales',
            'chain_key' => 'sales:'.Uuid::v4()->toRfc4122(),
            'chain_label' => 'Caisse test',
            'cause' => 'invalid_signature',
            'origin' => 'refusal',
            'status' => 'open',
            'first_event_at' => '2026-11-18 10:00:00',
            'last_event_at' => '2026-11-18 10:00:00',
            'technical_detail' => 'detail',
        ];
        $columns = array_keys($row);
        $this->db()->executeStatement(
            sprintf(
                'INSERT INTO platform_nf525_alert (%s) VALUES (%s)',
                implode(', ', $columns),
                implode(', ', array_fill(0, \count($columns), '?')),
            ),
            array_values($row),
        );

        return (string) $row['id'];
    }

    public function testTheVirtualColumnAndItsUniqueIndexExistInTheTestSchema(): void
    {
        $column = $this->db()->fetchAssociative(
            "SELECT GENERATION_EXPRESSION AS expr, EXTRA AS extra FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_nf525_alert' AND COLUMN_NAME = 'open_chain_key'",
        );
        self::assertIsArray($column, 'La colonne virtuelle open_chain_key manque : l’unicité ne serait testée que sur le papier de la migration.');
        self::assertStringContainsString('VIRTUAL', strtoupper((string) $column['extra']));

        $index = $this->db()->fetchAllAssociative(
            "SELECT COLUMN_NAME AS col, NON_UNIQUE AS non_unique FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'platform_nf525_alert' AND INDEX_NAME = 'uniq_nf525_alert_open_chain'",
        );
        self::assertCount(1, $index);
        self::assertSame('open_chain_key', $index[0]['col']);
        self::assertSame(0, (int) $index[0]['non_unique']);
    }

    public function testTwoOpenAlertsOnTheSameChainAreRefused(): void
    {
        $this->insertAlert(['chain_key' => 'sales:A']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertAlert(['chain_key' => 'sales:A']);
    }

    public function testAnOpenAndAResolvedAlertOnTheSameChainAreAccepted(): void
    {
        $this->insertAlert(['chain_key' => 'sales:A', 'status' => 'resolved', 'resolved_at' => '2026-11-18 11:00:00']);
        $this->insertAlert(['chain_key' => 'sales:A']);
        // Deux résolues sur la même chaîne : l'historique s'accumule.
        $this->insertAlert(['chain_key' => 'sales:A', 'status' => 'resolved', 'resolved_at' => '2026-11-18 12:00:00']);

        self::assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE chain_key = 'sales:A'"));
    }

    public function testTwoOpenAlertsOnDifferentChainsAreAccepted(): void
    {
        $this->insertAlert(['chain_key' => 'sales:A']);
        $this->insertAlert(['chain_key' => 'sales:B']);

        self::assertSame(2, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE status = 'open'"));
    }

    public function testTwoIdenticalReferencesAreRefused(): void
    {
        $this->insertAlert(['reference' => 'NF525-AAAAAAAA', 'chain_key' => 'sales:A']);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->insertAlert(['reference' => 'NF525-AAAAAAAA', 'chain_key' => 'sales:B']);
    }

    public function testOpenChainKeyIsNeverNullForAnOpenAlertAndNullForAResolvedOne(): void
    {
        $this->insertAlert(['chain_key' => 'sales:A']);
        $this->insertAlert(['chain_key' => 'accounting:P:J']);
        $this->insertAlert(['chain_key' => 'invoicing:P']);
        $this->insertAlert(['chain_key' => 'sales:R', 'status' => 'resolved']);

        // Témoin : les deux populations existent, sinon les deux assertions suivantes seraient vides.
        self::assertSame(3, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE status = 'open'"));
        self::assertSame(1, (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE status = 'resolved'"));

        self::assertSame(
            0,
            (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE status = 'open' AND open_chain_key IS NULL"),
            'Une alerte ouverte à open_chain_key NULL échapperait à l’unicité.',
        );
        self::assertSame(
            0,
            (int) $this->db()->fetchOne("SELECT COUNT(*) FROM platform_nf525_alert WHERE status = 'resolved' AND open_chain_key IS NOT NULL"),
        );
        self::assertSame('sales:A', $this->db()->fetchOne("SELECT open_chain_key FROM platform_nf525_alert WHERE chain_key = 'sales:A'"));
    }

    public function testNullStatusIsRefusedByTheDatabase(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->insertAlert(['status' => null]);
    }

    public function testNullChainKeyIsRefusedByTheDatabase(): void
    {
        $this->expectException(NotNullConstraintViolationException::class);
        $this->insertAlert(['chain_key' => null]);
    }

    public function testStatusDefaultsToOpenAndCountersToZero(): void
    {
        $id = Uuid::v4()->toBinary();
        $this->db()->executeStatement(
            "INSERT INTO platform_nf525_alert (id, reference, chain_type, chain_key, chain_label, cause, origin, first_event_at, last_event_at, technical_detail)
             VALUES (?, 'NF525-DEFAULT0', 'sales', 'sales:D', 'Caisse', 'unknown_key', 'refusal', '2026-11-18 10:00:00', '2026-11-18 10:00:00', 'x')",
            [$id],
        );

        $row = $this->db()->fetchAssociative('SELECT status, refusal_count, detection_count, open_chain_key FROM platform_nf525_alert WHERE id = ?', [$id]);
        self::assertSame('open', $row['status']);
        self::assertSame(0, (int) $row['refusal_count']);
        self::assertSame(0, (int) $row['detection_count']);
        self::assertSame('sales:D', $row['open_chain_key']);
    }

    public function testDeletingAnAlertDeletesItsEstablishmentLinks(): void
    {
        $etabA = $this->idEtablissement(SocleFixtures::ETAB_A_NOM);
        $etabB = $this->em()->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_B_NOM]);
        self::assertInstanceOf(Etablissement::class, $etabB);

        $kept = $this->insertAlert(['chain_key' => 'sales:KEPT']);
        $gone = $this->insertAlert(['chain_key' => 'sales:GONE']);
        foreach ([[$gone, $etabA], [$gone, (string) $etabB->getId()], [$kept, $etabA]] as [$alert, $etab]) {
            $this->db()->executeStatement(
                'INSERT INTO platform_nf525_alert_establishment (alert_id, establishment_id) VALUES (?, ?)',
                [$alert, Uuid::fromString($etab)->toBinary()],
            );
        }
        self::assertSame(3, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment'));

        $this->db()->executeStatement('DELETE FROM platform_nf525_alert WHERE id = ?', [$gone]);

        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM platform_nf525_alert_establishment'));
        self::assertSame(
            $kept,
            $this->db()->fetchOne('SELECT alert_id FROM platform_nf525_alert_establishment'),
            'Seule la liaison de l’alerte conservée subsiste.',
        );
    }

    public function testAnEstablishmentLinkedToAnAlertCannotBeDeletedEvenWhenResolved(): void
    {
        // Établissements neufs, sans autre lien : ceux des fixtures portent d'autres clés étrangères
        // (affectations, points de vente) qui refuseraient la suppression pour une autre raison.
        $linked = $this->insertBareEstablishment('Établissement lié');
        $free = $this->insertBareEstablishment('Établissement libre');
        $alert = $this->insertAlert(['chain_key' => 'sales:R', 'status' => 'resolved', 'resolved_at' => '2026-11-18 11:00:00']);
        $this->db()->executeStatement(
            'INSERT INTO platform_nf525_alert_establishment (alert_id, establishment_id) VALUES (?, ?)',
            [$alert, $linked],
        );

        try {
            $this->db()->executeStatement('DELETE FROM org_etablissement WHERE id = ?', [$linked]);
            self::fail('La FK RESTRICT aurait dû refuser la suppression.');
        } catch (ForeignKeyConstraintViolationException $e) {
            self::assertStringContainsString('platform_nf525_alert_establishment', $e->getMessage());
        }
        self::assertSame(1, (int) $this->db()->fetchOne('SELECT COUNT(*) FROM org_etablissement WHERE id = ?', [$linked]));

        // Témoin : le même établissement neuf, sans alerte, se supprime ; le refus vient donc de la liaison.
        self::assertSame(1, $this->db()->executeStatement('DELETE FROM org_etablissement WHERE id = ?', [$free]));
    }

    private function insertBareEstablishment(string $name): string
    {
        $id = Uuid::v4()->toBinary();
        $this->db()->executeStatement(
            'INSERT INTO org_etablissement (id, nom, actif, region_id) SELECT ?, ?, 1, region_id FROM org_etablissement LIMIT 1',
            [$id, $name],
        );

        return $id;
    }

    public function testTheEntityPersistsItsLinksAndEnumsThroughTheOrm(): void
    {
        $em = $this->em();
        $etab = $em->getRepository(Etablissement::class)->findOneBy(['nom' => SocleFixtures::ETAB_A_NOM]);
        self::assertInstanceOf(Etablissement::class, $etab);

        $pos = Uuid::v4();
        $alert = (new Nf525Alert('NF525-0A1B2C3D', SealChainType::Sales, 'sales:'.$pos->toRfc4122(), 'Caisse 1', SealRefusalCause::KeyRegression, SealAlertOrigin::Refusal))
            ->withChainIds($pos, null, null)
            ->withTechnicalDetail('maillon 12')
            ->addEstablishment($etab);
        $em->persist($alert);
        $em->flush();
        $em->clear();

        $loaded = $em->find(Nf525Alert::class, $alert->getId());
        self::assertInstanceOf(Nf525Alert::class, $loaded);
        self::assertSame(SealAlertStatus::Open, $loaded->getStatus());
        self::assertSame(SealRefusalCause::KeyRegression, $loaded->getCause());
        self::assertSame(SealChainType::Sales, $loaded->getChainType());
        self::assertTrue($pos->equals($loaded->getPointOfSaleId()));
        self::assertCount(1, $loaded->getEstablishments());
        self::assertSame(
            'sales:'.$pos->toRfc4122(),
            $this->db()->fetchOne('SELECT open_chain_key FROM platform_nf525_alert WHERE id = ?', [$alert->getId()->toBinary()]),
        );
    }
}
