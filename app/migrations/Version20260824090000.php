<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * CQ-5 (`plan-cq5.md` §4) : `IssueCreditNoShow` sur `RegleAnnulation` (défaut
 * `restored_with_reschedule`, D27) + traçabilité crédit sur `FacturationNoShow`
 * (`issue_credit_no_show`/`credit_actionne`/`credit_restitue`).
 *
 * ⚠ Migration écrite à la main (DDL, cf. plan §4, même précaution que `Version20260822093000.php` —
 * `doctrine:migrations:diff` propose systématiquement des suppressions d'index sur ce dépôt). Relue
 * ligne à ligne : les deux `ALTER TABLE` ne touchent que `reservation_regle_annulation`/
 * `reservation_facturation_no_show` — aucun index, aucune contrainte, aucune colonne d'un autre module
 * n'est déplacée ni droppée. `uniq_facturation_no_show_reservation` (pivot de RG-CQ5-07) n'est pas
 * touchée.
 */
final class Version20260824090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'CQ-5 : IssueCreditNoShow sur RegleAnnulation (défaut restored_with_reschedule) + traçabilité '
            . 'crédit sur FacturationNoShow (issueCreditNoShow/creditActionne/creditRestitue).';
    }

    public function up(Schema $schema): void
    {
        // RegleAnnulation — colonne NOT NULL avec DEFAULT constant (D27) : la valeur de repli est la
        // même pour toutes les lignes existantes -> un simple ADD COLUMN ... NOT NULL DEFAULT suffit
        // et reste rejouable (MariaDB remplit les lignes existantes avec le DEFAULT à l'ALTER).
        $this->addSql("ALTER TABLE reservation_regle_annulation ADD issue_credit_no_show VARCHAR(24) DEFAULT 'restored_with_reschedule' NOT NULL");

        // FacturationNoShow — nullable pour issue_credit_no_show (aucune règle active possible,
        // RG-CQ5-03 ; et rows historiques antérieures à ce lot, sans valeur connue). credit_actionne/
        // credit_restitue NOT NULL DEFAULT 0 : correct pour l'historique — le mécanisme n'existait pas
        // avant ce lot, donc aucun crédit n'a JAMAIS été actionné/restitué par le passé (§3.2 spec).
        $this->addSql('ALTER TABLE reservation_facturation_no_show ADD issue_credit_no_show VARCHAR(24) DEFAULT NULL, ADD credit_actionne TINYINT DEFAULT 0 NOT NULL, ADD credit_restitue TINYINT DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE reservation_facturation_no_show DROP issue_credit_no_show, DROP credit_actionne, DROP credit_restitue');
        $this->addSql('ALTER TABLE reservation_regle_annulation DROP issue_credit_no_show');
    }
}
