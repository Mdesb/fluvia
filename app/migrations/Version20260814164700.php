<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Migration de données M1 (L1) : insère les permissions du module « offre » dans le référentiel
 * sec_permission (RG-SOCLE-02), comme pour le socle. Le couple (module, action) est unique ;
 * l'insertion est idempotente (INSERT IGNORE). L'affectation aux rôles relève de l'administration
 * (module M8) / des fixtures.
 */
final class Version20260814164700 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS = ['creer', 'lire', 'modifier', 'publier', 'archiver', 'gerer', 'modifier_compta'];

    public function getDescription(): string
    {
        return 'M1 Offre & Tarification (L1) : permissions offre.{creer,lire,modifier,publier,archiver,gerer,modifier_compta}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'offre', $action]
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'offre'");
    }
}
