<?php

declare(strict_types=1);

namespace App\Tests\Compta\Integration;

use App\Compta\DataFixtures\ComptaFixtures;
use App\Tests\Compta\ComptaApiTestCase;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\ORM\EntityManagerInterface;
use DoctrineMigrations\Version20261121110000;
use Psr\Log\NullLogger;

require_once \dirname(__DIR__, 3) . '/migrations/Version20261121110000.php';

/**
 * G-5 : le droit `payment.configure` est semé par migration, idempotente, et rattaché au seul rôle
 * « Administrateur groupe ». On joue le SQL de la migration elle-même.
 */
final class PaymentConfigureMigrationTest extends ComptaApiTestCase
{
    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }

    private function em(): EntityManagerInterface
    {
        return static::getContainer()->get('doctrine')->getManager();
    }

    private function play(string $direction): void
    {
        $migration = new Version20261121110000($this->connection(), new NullLogger());
        $migration->{$direction}(new Schema());
        foreach ($migration->getSql() as $query) {
            $this->connection()->executeStatement($query->getStatement(), $query->getParameters(), $query->getTypes());
        }
    }

    private function countRows(string $sql): int
    {
        return (int) $this->connection()->fetchOne($sql, ['module' => 'payment', 'action' => 'configure']);
    }

    private function permissions(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM sec_permission WHERE module = :module AND action = :action');
    }

    private function attachments(): int
    {
        return $this->countRows('SELECT COUNT(*) FROM sec_role_permission rp JOIN sec_permission p ON p.id = rp.permission_id WHERE p.module = :module AND p.action = :action');
    }

    /** @return list<string> */
    private function roles(): array
    {
        return $this->connection()->fetchFirstColumn(
            'SELECT r.nom FROM sec_role r JOIN sec_role_permission rp ON rp.role_id = r.id JOIN sec_permission p ON p.id = rp.permission_id WHERE p.module = :module AND p.action = :action',
            ['module' => 'payment', 'action' => 'configure'],
        );
    }

    public function testFixturesAndMigrationAgree(): void
    {
        // Témoin positif : les fixtures ont semé le droit pour le seul administrateur socle.
        self::assertSame(1, $this->permissions());
        self::assertSame(['Administrateur groupe'], $this->roles());

        // Passée après les fixtures, la migration n'ajoute rien.
        $this->play('up');
        self::assertSame(1, $this->permissions());
        self::assertSame(1, $this->attachments());
    }

    public function testUpIsIdempotentAndAttachesOnlyTheGroupAdministrator(): void
    {
        $this->play('down');
        // Témoin positif : la base est bien vide de ce droit avant le rejeu.
        self::assertSame(0, $this->permissions());
        self::assertSame(0, $this->attachments());

        $this->play('up');
        $this->play('up');

        self::assertSame(1, $this->permissions());
        self::assertSame(1, $this->attachments());
        self::assertSame(['Administrateur groupe'], $this->roles());
    }

    public function testFixturesAfterMigrationAddNothing(): void
    {
        $this->play('down');
        $this->play('up');

        static::getContainer()->get(ComptaFixtures::class)->load($this->em());
        $this->em()->clear();

        self::assertSame(1, $this->permissions());
        self::assertSame(1, $this->attachments());
        self::assertSame(['Administrateur groupe'], $this->roles());
    }

    public function testDownRemovesEverything(): void
    {
        self::assertSame(1, $this->attachments());

        $this->play('down');

        self::assertSame(0, $this->attachments());
        self::assertSame(0, $this->permissions());
    }
}
