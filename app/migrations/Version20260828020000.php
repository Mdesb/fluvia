<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Fidélité et parrainage — six tables, aucune donnée.
 *
 * DDL **relevé sur le mapping** (`doctrine:schema:update --dump-sql`), jamais écrit de mémoire
 * (D32). Un `CREATE TABLE` inventé se découvre en production, sur une colonne qui manque.
 *
 * Aucune donnée métier n'est posée ici (D66-ter) : le barème, les paliers et le programme de
 * parrainage sont des DÉCISIONS d'exploitant. Une migration qui les inventerait engagerait
 * l'établissement sur une promesse que personne n'a faite — et un barème par défaut est une
 * promesse.
 *
 * Rien n'est détruit : la descente ne fait que retirer ce que la montée a créé.
 */
final class Version20260828020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Fidélité (barème daté, paliers, écritures) et parrainage (programme, codes, liens).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_loyalty_rule (
                id BINARY(16) NOT NULL,
                points_per_euro INT DEFAULT 1 NOT NULL,
                valid_from DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_790701938565851 (establishment_id),
                UNIQUE INDEX uniq_marketing_loyalty_rule_debut (establishment_id, valid_from),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_loyalty_tier (
                id BINARY(16) NOT NULL,
                label VARCHAR(60) NOT NULL,
                threshold INT NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_1B413AD58565851 (establishment_id),
                UNIQUE INDEX uniq_marketing_loyalty_tier_libelle (establishment_id, label),
                UNIQUE INDEX uniq_marketing_loyalty_tier_seuil (establishment_id, threshold),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_loyalty_entry (
                id BINARY(16) NOT NULL,
                customer_ref BINARY(16) NOT NULL,
                points INT NOT NULL,
                movement VARCHAR(16) NOT NULL,
                reason VARCHAR(200) NOT NULL,
                author_ref BINARY(16) DEFAULT NULL,
                created_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_D0CA0EB88565851 (establishment_id),
                INDEX idx_marketing_loyalty_entry_client (establishment_id, customer_ref),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_referral_program (
                id BINARY(16) NOT NULL,
                reward_points INT DEFAULT 100 NOT NULL,
                minimum_purchase NUMERIC(10, 2) DEFAULT '10.00' NOT NULL,
                enabled TINYINT DEFAULT 1 NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_marketing_referral_program (establishment_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_referral_code (
                id BINARY(16) NOT NULL,
                sponsor_ref BINARY(16) NOT NULL,
                code VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_355A36F98565851 (establishment_id),
                UNIQUE INDEX uniq_marketing_referral_code (establishment_id, code),
                UNIQUE INDEX uniq_marketing_referral_code_parrain (establishment_id, sponsor_ref),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        // `uniq_marketing_referral_filleul` n'est pas une commodité : c'est LA règle du programme.
        // Un filleul se parraine une fois, jamais deux. Un contrôle applicatif se ferait doubler
        // par deux requêtes simultanées ; une contrainte d'unicité, non.
        $this->addSql(<<<'SQL'
            CREATE TABLE marketing_referral (
                id BINARY(16) NOT NULL,
                sponsor_ref BINARY(16) NOT NULL,
                referee_ref BINARY(16) NOT NULL,
                code VARCHAR(16) NOT NULL,
                created_at DATETIME NOT NULL,
                rewarded_at DATETIME DEFAULT NULL,
                rewarded_points INT DEFAULT 0 NOT NULL,
                establishment_id BINARY(16) NOT NULL,
                INDEX IDX_EABEEEA8565851 (establishment_id),
                INDEX idx_marketing_referral_parrain (establishment_id, sponsor_ref),
                UNIQUE INDEX uniq_marketing_referral_filleul (establishment_id, referee_ref),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        foreach ([
            'marketing_loyalty_rule' => 'FK_790701938565851',
            'marketing_loyalty_tier' => 'FK_1B413AD58565851',
            'marketing_loyalty_entry' => 'FK_D0CA0EB88565851',
            'marketing_referral_program' => 'FK_6BC12D568565851',
            'marketing_referral_code' => 'FK_355A36F98565851',
            'marketing_referral' => 'FK_EABEEEA8565851',
        ] as $table => $contrainte) {
            $this->addSql(sprintf(
                'ALTER TABLE %s ADD CONSTRAINT %s FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)',
                $table,
                $contrainte,
            ));
        }
    }

    public function down(Schema $schema): void
    {
        // L'ordre inverse de la création : les clés étrangères tombent avec leurs tables.
        foreach ([
            'marketing_referral',
            'marketing_referral_code',
            'marketing_referral_program',
            'marketing_loyalty_entry',
            'marketing_loyalty_tier',
            'marketing_loyalty_rule',
        ] as $table) {
            $this->addSql('DROP TABLE ' . $table);
        }
    }
}
