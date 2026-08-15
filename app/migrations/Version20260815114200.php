<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Verticale Sport/Fitness — migration de données : insère les permissions du module « sport »
 * (RG-SOCLE-02, dérivées du tableau Acteurs & droits de la spec §3). Idempotent (INSERT IGNORE),
 * même patron que `Version20260815060806` (L6 Piscine). L'affectation aux rôles relève de
 * l'administration (M8) / des fixtures.
 *
 * ⚠ HYPOTHÈSE (plan §4, point ouvert) : les noms de permissions `sport.*` dérivent du tableau
 * Acteurs & droits mais ne sont pas nommés littéralement dans les sources — à figer avec M8.
 */
final class Version20260815114200 extends AbstractMigration
{
    /** @var list<string> */
    private const ACTIONS = [
        'gerer_abonnement',
        'piloter_impayes',
        'forcer_acces',
        'lire',
        'parametrer',
        'configurer_nocturne',
        'superviser_nocturne',
        'lire_soi',
        'resoudre_impaye_soi',
        'pause_demander_soi',
        'resilier_demander_soi',
    ];

    public function getDescription(): string
    {
        return 'Verticale Sport/Fitness : permissions sport.{gerer_abonnement,piloter_impayes,forcer_acces,lire,'
            . 'parametrer,configurer_nocturne,superviser_nocturne,lire_soi,resoudre_impaye_soi,pause_demander_soi,'
            . 'resilier_demander_soi}.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::ACTIONS as $action) {
            $this->addSql(
                'INSERT IGNORE INTO sec_permission (id, module, action) VALUES (?, ?, ?)',
                [Uuid::v4()->toBinary(), 'sport', $action],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM sec_permission WHERE module = 'sport'");
    }
}
