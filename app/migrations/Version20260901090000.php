<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Addendum FIN-4 (alertes de trésorerie proactives, RG-TRE-10, §0.2/§4 de `plan-treasury-cash-alerts.md`)
 * — deux colonnes additives sur `finance_treasury_settings`, compatibles avec le code déployé avant
 * elles (`cash_alert_threshold_cents` nullable sans défaut — `null` **est** le défaut, alerte
 * désactivée ; `cash_alert_horizon_days` avec défaut `30`, même patron que `unmatched_alert_delay_days`/
 * `matching_window_days` déjà sur cette table).
 */
final class Version20260901090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (addendum FIN-4, alertes de trésorerie) : cash_alert_threshold_cents/cash_alert_horizon_days sur finance_treasury_settings.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_treasury_settings ADD cash_alert_threshold_cents INT DEFAULT NULL');
        $this->addSql('ALTER TABLE finance_treasury_settings ADD cash_alert_horizon_days INT DEFAULT 30 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE finance_treasury_settings DROP cash_alert_threshold_cents');
        $this->addSql('ALTER TABLE finance_treasury_settings DROP cash_alert_horizon_days');
    }
}
