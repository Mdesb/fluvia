<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Addendum FIN-4 (alertes de trésorerie proactives, RG-TRE-13, §0.3/§4 de `plan-treasury-cash-alerts.md`)
 * — nouvelle table `finance_treasury_cash_alert`, brique entièrement nouvelle (aucune table existante
 * autre que `finance_treasury_settings`, cf. `Version20260901090000`, n'est modifiée).
 *
 * **Anti-répétition élevée au niveau base** : `open_establishment_id` est une colonne **générée
 * virtuelle** (`NULL` tant que `status != 'open'`) portant un index `UNIQUE` — MariaDB exclut les
 * valeurs `NULL` d'un index unique, donc seules les lignes `open` sont soumises à l'unicité : au plus
 * une alerte `open` par établissement, garanti par la base, pas seulement par un `findOneBy()`
 * applicatif dans `finance:treasury:verifier-seuils`. La même contrainte est reproduite côté schéma de
 * test par `App\Finance\Treasury\Doctrine\TreasuryCashAlertSchemaListener` (le schéma de test est
 * construit depuis les métadonnées ORM, jamais rejoué depuis ces migrations).
 *
 * `cause_source_id` : référence polymorphe **sans FK** (comme `PaymentScheduleCalculator::
 * echeancier()['exits'][]['sourceId']` dont elle est la copie) — `supplier_invoice` aujourd'hui, une
 * autre source de sortie demain sans nouvelle migration.
 */
final class Version20260901090100 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'App\\Finance\\Treasury (addendum FIN-4, alertes de trésorerie) : nouvelle table finance_treasury_cash_alert.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE finance_treasury_cash_alert (
                id BINARY(16) NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                status VARCHAR(10) DEFAULT 'open' NOT NULL,
                threshold_cents_at_detection INT NOT NULL,
                horizon_days_at_detection INT NOT NULL,
                projected_breach_date DATE NOT NULL,
                projected_balance_cents INT NOT NULL,
                cause_source VARCHAR(32) DEFAULT NULL,
                cause_source_id BINARY(16) DEFAULT NULL,
                cause_amount_cents INT DEFAULT NULL,
                detected_at DATETIME NOT NULL,
                last_checked_at DATETIME NOT NULL,
                last_notified_at DATETIME DEFAULT NULL,
                resolved_at DATETIME DEFAULT NULL,
                open_establishment_id BINARY(16) GENERATED ALWAYS AS (CASE WHEN status = 'open' THEN establishment_id END) VIRTUAL,
                INDEX idx_treasury_cash_alert_establishment (establishment_id),
                INDEX idx_treasury_cash_alert_status (status),
                UNIQUE INDEX uniq_treasury_cash_alert_open_establishment (open_establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);
        $this->addSql('ALTER TABLE finance_treasury_cash_alert ADD CONSTRAINT FK_FIN_TRE_CAL_ESTABLISHMENT FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE finance_treasury_cash_alert');
    }
}
