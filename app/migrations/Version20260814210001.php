<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * L3 Contrôle d'accès — migration de données : insère les permissions du module « acces »
 * (RG-SOCLE-02, dérivées du tableau Acteurs & droits de la spec §3). Insertion idempotente
 * (INSERT IGNORE, couple module/action unique). L'affectation aux rôles relève de M8 / des fixtures.
 *
 * ⚠ HYPOTHÈSE (plan §5, point ouvert n°2) : les noms de permissions dérivent du modèle socle mais ne
 * sont pas nommés littéralement dans les sources ; découpage fin à figer avec M8.
 */
final class Version20260814210001 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS = ['gerer', 'lire', 'superviser', 'appairer', 'ouvrir_manuel', 'controler', 'bloquer_support', 'ingestion'];

    public function getDescription(): string
    {
        return 'L3 (Contrôle d\'accès) : permissions acces.{gerer,lire,superviser,appairer,ouvrir_manuel,controler,bloquer_support,ingestion}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'acces', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'acces'");
    }
}
