<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Correction du moyen de paiement d'une facture : moins sur l'un, plus sur l'autre.
 *
 * Demandé par Maxime : « on fait moins sur le moyen de paiement initial et plus sur le nouveau moyen
 * de paiement […] un bouton corriger le paiement, et automatiquement les écritures qui vont avec ».
 *
 * ── UNE COMPENSATION, JAMAIS UNE SUPPRESSION (D45) ──────────────────────────────────────────────
 *
 * Le dépôt a déjà tranché ce point pour les ventes (`vente_settlement_correction`) : une correction
 * de règlement est une écriture compensatoire datée du JOUR DU GESTE. Un règlement encaissé puis
 * effacé laisse une caisse qui ne tombe plus juste et rien n'explique l'écart ; une contre-passation
 * se lit.
 *
 * Cette table est le pendant pour les factures.
 *
 * ── LES DEUX LIGNES PRODUITES SONT CONSERVÉES, ET C'EST LE POINT ────────────────────────────────
 *
 * `debit_entry_id` et `credit_entry_id` désignent les deux `facturation_reglement` que la correction
 * a créées. Sans elles, une ligne de règlement négative apparaîtrait dans un relevé sans la moindre
 * explication, et rien ne distinguerait une correction d'un encaissement ordinaire.
 *
 * `ON DELETE RESTRICT` implicite sur les deux : supprimer une ligne compensée laisserait une
 * correction qui désigne le vide, et un solde faux.
 *
 * DDL relevé sur le mapping (D32), écrit à la main.
 */
final class Version20260829120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Correction du moyen de paiement d’une facture (D45).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE invoice_settlement_correction (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                invoice_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                debited_method VARCHAR(32) NOT NULL,
                credited_method VARCHAR(32) NOT NULL,
                amount NUMERIC(10, 2) NOT NULL,
                reason VARCHAR(255) NOT NULL,
                author_id BINARY(16) DEFAULT NULL COMMENT '(DC2Type:uuid)',
                occurred_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                debit_entry_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                credit_entry_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_invoice_correction_invoice (invoice_id),
                INDEX IDX_invoice_correction_author (author_id),
                INDEX IDX_invoice_correction_debit (debit_entry_id),
                INDEX IDX_invoice_correction_credit (credit_entry_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE invoice_settlement_correction ADD CONSTRAINT FK_invoice_correction_invoice FOREIGN KEY (invoice_id) REFERENCES facturation_facture (id)');
        $this->addSql('ALTER TABLE invoice_settlement_correction ADD CONSTRAINT FK_invoice_correction_author FOREIGN KEY (author_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE invoice_settlement_correction ADD CONSTRAINT FK_invoice_correction_debit FOREIGN KEY (debit_entry_id) REFERENCES facturation_reglement (id)');
        $this->addSql('ALTER TABLE invoice_settlement_correction ADD CONSTRAINT FK_invoice_correction_credit FOREIGN KEY (credit_entry_id) REFERENCES facturation_reglement (id)');
    }

    public function down(Schema $schema): void
    {
        // ⚠ Redescendre supprime la TRAÇABILITÉ des corrections, pas leur effet : les deux lignes de
        // règlement compensatoires restent dans `facturation_reglement`, et le solde des factures est
        // donc inchangé. On perd la raison, l'auteur et le lien entre les deux lignes — ce qui est
        // exactement ce qu'on ne veut pas perdre, mais jamais l'équilibre comptable.
        $this->addSql('DROP TABLE invoice_settlement_correction');
    }
}
