<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * FAC-1 — les pièces commerciales avant la facture : quote, bon de commande, bon de livraison.
 *
 * **Une table à natures plutôt que trois tables jumelles.** Les trois pièces portent les mêmes
 * choses — destinataire, lignes, totaux, état, filiation — et ce qui les distingue tient en trois
 * phrases. Trois tables auraient triplé la ligne, les totaux et les index, et divergé au premier
 * correctif appliqué à une seule. C'est le choix déjà fait par `facturation_facture`, qui porte sa
 * `nature`.
 *
 * **`document_origine_id` est une auto-référence, en `SET NULL`.** Elle porte la filiation :
 * quel quote a produit quelle commande, quelle commande quel bon de livraison. En `CASCADE`, purger
 * un vieux quote emporterait la commande et la livraison qui en sont issues — c'est-à-dire les pièces
 * les plus récentes, celles qu'on voulait garder.
 *
 * **`quantite_livree` est sur la ligne, pas sur le document.** Une livraison partielle ne l'est
 * jamais en bloc : sur dix articles commandés, sept arrivent et trois manquent. Un compteur au niveau
 * du document ne saurait pas dire lesquels.
 *
 * Conforme à D32 : draft de `doctrine:migrations:diff` jeté — 124 instructions dont 16
 * appartenaient à ce lot. Horodatage en heure locale (10:20) ; le draft naissait `081931`, en
 * UTC, et se serait classé avant des migrations déjà appliquées.
 */
final class Version20260826102000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'FAC-1 : quote, bon de commande et bon de livraison, avec leur filiation.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE billing_document (
                id BINARY(16) NOT NULL,
                nature VARCHAR(20) NOT NULL,
                numero VARCHAR(32) DEFAULT NULL,
                statut VARCHAR(20) NOT NULL,
                date_creation DATETIME NOT NULL,
                date_emission DATETIME DEFAULT NULL,
                date_validite DATE DEFAULT NULL,
                total_ht NUMERIC(12, 2) NOT NULL,
                total_tva NUMERIC(12, 2) NOT NULL,
                total_ttc NUMERIC(12, 2) NOT NULL,
                facture_id BINARY(16) DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                profil_exploitant_id BINARY(16) NOT NULL,
                destinataire_id BINARY(16) NOT NULL,
                document_origine_id BINARY(16) DEFAULT NULL,
                cree_par_id BINARY(16) NOT NULL,
                INDEX IDX_CB4EB6CCFF631228 (etablissement_id),
                INDEX IDX_CB4EB6CC53ABECD4 (profil_exploitant_id),
                INDEX IDX_CB4EB6CCA4F84F6E (destinataire_id),
                INDEX IDX_CB4EB6CCA14432D8 (document_origine_id),
                INDEX IDX_CB4EB6CCFC29C013 (cree_par_id),
                INDEX idx_document_establishment_nature (etablissement_id, nature),
                UNIQUE INDEX uniq_document_number (numero),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE billing_document_line (
                id BINARY(16) NOT NULL,
                designation VARCHAR(255) NOT NULL,
                quantite INT NOT NULL,
                quantite_livree INT NOT NULL,
                prix_unitaire_ht NUMERIC(12, 2) NOT NULL,
                montant_ht NUMERIC(12, 2) NOT NULL,
                montant_tva NUMERIC(12, 2) NOT NULL,
                montant_ttc NUMERIC(12, 2) NOT NULL,
                ligne_origine BINARY(16) DEFAULT NULL,
                document_id BINARY(16) NOT NULL,
                taux_tva_id BINARY(16) DEFAULT NULL,
                INDEX IDX_C25442CBC33F7837 (document_id),
                INDEX IDX_C25442CBF7FEBCCE (taux_tva_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4
            SQL);

        foreach ([
            'FK_CB4EB6CCFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)',
            'FK_CB4EB6CC53ABECD4 FOREIGN KEY (profil_exploitant_id) REFERENCES compta_profil_exploitant (id)',
            'FK_CB4EB6CCA4F84F6E FOREIGN KEY (destinataire_id) REFERENCES facturation_destinataire (id)',
            'FK_CB4EB6CCA14432D8 FOREIGN KEY (document_origine_id) REFERENCES billing_document (id) ON DELETE SET NULL',
            'FK_CB4EB6CCFC29C013 FOREIGN KEY (cree_par_id) REFERENCES sec_utilisateur (id)',
        ] as $contrainte) {
            $this->addSql('ALTER TABLE billing_document ADD CONSTRAINT '.$contrainte);
        }

        $this->addSql('ALTER TABLE billing_document_line ADD CONSTRAINT FK_C25442CBC33F7837 FOREIGN KEY (document_id) REFERENCES billing_document (id)');
        $this->addSql('ALTER TABLE billing_document_line ADD CONSTRAINT FK_C25442CBF7FEBCCE FOREIGN KEY (taux_tva_id) REFERENCES compta_taux_tva (id)');
    }

    public function down(Schema $schema): void
    {
        foreach ([
            'billing_document DROP FOREIGN KEY FK_CB4EB6CCFF631228',
            'billing_document DROP FOREIGN KEY FK_CB4EB6CC53ABECD4',
            'billing_document DROP FOREIGN KEY FK_CB4EB6CCA4F84F6E',
            'billing_document DROP FOREIGN KEY FK_CB4EB6CCA14432D8',
            'billing_document DROP FOREIGN KEY FK_CB4EB6CCFC29C013',
            'billing_document_line DROP FOREIGN KEY FK_C25442CBC33F7837',
            'billing_document_line DROP FOREIGN KEY FK_C25442CBF7FEBCCE',
        ] as $instruction) {
            $this->addSql('ALTER TABLE '.$instruction);
        }

        $this->addSql('DROP TABLE billing_document_line');
        $this->addSql('DROP TABLE billing_document');
    }
}
