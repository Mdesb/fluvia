<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * M2 Vente & Caisse (L2) — migration de données : insère les permissions des modules « vente » et
 * « caisse » (RG-SOCLE-02, dérivées du tableau Acteurs & droits selon le modèle module × action).
 * Le couple (module, action) est unique ; insertion idempotente (INSERT IGNORE). L'affectation aux
 * rôles relève de l'administration (M8) / des fixtures.
 *
 * ⚠ HYPOTHÈSE (plan §5) : les noms de permissions dérivent du modèle socle mais ne sont pas nommés
 * littéralement dans les sources ; découpage fin à figer avec M8.
 */
final class Version20260814175519 extends AbstractMigration
{
    /** @var array<string, list<string>> */
    private const PERMISSIONS = [
        'vente' => ['lire', 'creer', 'encaisser', 'annuler', 'rembourser', 'forcer_prix'],
        'caisse' => ['lire', 'ouvrir', 'cloturer', 'mouvement', 'gerer'],
    ];

    public function getDescription(): string
    {
        return 'M2 (L2) : permissions vente.{lire,creer,encaisser,annuler,rembourser,forcer_prix} et caisse.{lire,ouvrir,cloturer,mouvement,gerer}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::PERMISSIONS as $module => $actions) {
            foreach ($actions as $action) {
                $this->addSql(
                    'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                    [Uuid::v4()->toBinary(), $module, $action]
                );
            }
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module IN ('vente', 'caisse')");
    }
}
