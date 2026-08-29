<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Ventilation de l'encaissement par moyen de paiement : « ce moyen s'encaisse sur ce compte ».
 *
 * Avant, une vente produisait UNE ligne de débit sur un compte unique, quel que soit le mode de
 * règlement — espèces, carte, chèque et virement confondus sur le 511. Un rapprochement bancaire ne
 * peut rien en faire : les espèces vivent en 53, les chèques à l'encaissement en 5112, la banque en
 * 512. La vente enregistre pourtant le moyen depuis toujours ; seule la comptabilité l'ignorait.
 *
 * ── LA VENTILATION APPARTIENT AU PROFIL EXPLOITANT, PAS AU MOYEN ────────────────────────────────
 *
 * `compta_moyen_paiement` est un référentiel GLOBAL — son code est unique pour tout le dépôt et il
 * ne porte pas d'établissement. Y écrire un numéro de compte le rendrait commun à tous les clients,
 * alors qu'un exploitant public tient un plan M57 et un privé un plan PCG. Le même « espèces » n'a
 * pas le même compte chez les deux, et se tromper produit des journaux FAUX — pire qu'une
 * fonctionnalité absente.
 *
 * Cette table est donc le pendant de `compta_mapping_comptable` : celle-là décrit le CRÉDIT
 * (catégorie de produit → compte de produit), celle-ci le DÉBIT.
 *
 * ── LES NOMS SONT EN ANGLAIS (D5) ───────────────────────────────────────────────────────────────
 *
 * Table et colonnes neuves. Les tables RÉFÉRENCÉES gardent leurs noms historiques français : seules
 * les déclarations sont tenues par la règle.
 *
 * ── AUCUNE SUPPRESSION EN CASCADE SUR LE COMPTE, DÉLIBÉRÉMENT ───────────────────────────────────
 *
 * Un compte encore désigné par une ventilation ne doit pas disparaître sous ses pieds : la
 * ventilation deviendrait muette et les écritures repartiraient en silence sur le compte unique.
 * Retirer une ventilation est un geste, pas un effet de bord.
 *
 * DDL relevé sur le mapping (D32), écrit à la main.
 */
final class Version20260829060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ventilation de l’encaissement par moyen de paiement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE accounting_payment_method_account (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                business_profile_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                payment_method_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                account_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_payment_method_account_profile (business_profile_id),
                INDEX IDX_payment_method_account_method (payment_method_id),
                INDEX IDX_payment_method_account_account (account_id),
                UNIQUE INDEX uniq_payment_method_account (business_profile_id, payment_method_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE accounting_payment_method_account ADD CONSTRAINT FK_payment_method_account_profile FOREIGN KEY (business_profile_id) REFERENCES compta_profil_exploitant (id)');
        $this->addSql('ALTER TABLE accounting_payment_method_account ADD CONSTRAINT FK_payment_method_account_method FOREIGN KEY (payment_method_id) REFERENCES compta_moyen_paiement (id)');
        $this->addSql('ALTER TABLE accounting_payment_method_account ADD CONSTRAINT FK_payment_method_account_account FOREIGN KEY (account_id) REFERENCES compta_compte_comptable (id)');
    }

    public function down(Schema $schema): void
    {
        // Redescendre supprime les ventilations : toutes les ventes repartent sur le compte
        // d'encaissement unique — le comportement d'avant, pas une perte de donnée comptable. Les
        // écritures déjà générées et scellées ne bougent pas, ce qui est le seul comportement
        // acceptable pour du NF525.
        $this->addSql('DROP TABLE accounting_payment_method_account');
    }
}
