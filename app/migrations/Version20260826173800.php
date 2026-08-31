<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * PAY-3 : `sale_card_rejection` — un refus de carte s'ecrit avant d'etre annonce.
 *
 * ⚠ Migration ecrite a la main (D32), horodatee en **heure locale** (17:38). DDL releve par `SHOW CREATE TABLE` sur la
 * table que Doctrine cree depuis le mapping, noms d'index et de contraintes compris.
 *
 * **Pourquoi une table et pas seulement un evenement.** Le contrat demande par `claude-D` ne portait
 * qu'un message sur le bus. Mais elle a elle-meme etabli que sa bascule carte vers prelevement **n'a
 * aucun client aujourd'hui** : aucun debit recurrent sur carte n'existe dans le produit. Son abonne
 * est donc le seul consommateur, et il n'agira sur **aucun** refus. Publier sans ecrire ne laisserait
 * litteralement aucune trace de la totalite des refus de carte — pas « en cas de panne », mais dans le
 * fonctionnement normal, des le premier jour.
 *
 * **Et un refus de carte n'est pas une notification, c'est un fait d'exploitation.** Un exploitant
 * voudra les compter : combien ce mois-ci, sur quel point de vente, a quelle heure. Un client
 * contestera un prelevement en affirmant que sa carte n'a jamais ete refusee. Ni l'une ni l'autre de
 * ces questions n'a de reponse si le fait ne vit que dans un message.
 *
 * `montant_centimes` en **entier** : la convention du depot (`bcmath` n'est pas installe), et surtout
 * le montant voyage jusqu'au preavis SEPA, ou `DebitPreNotifier::covers()` compare l'annonce et le
 * prelevement. Un arrondi en route ferait reconnaitre « une annonce qui ressemble a la bonne sans en
 * etre une ».
 *
 * L'index `idx_card_rejection_etab_date` sert la seule lecture prevue : les refus d'un etablissement
 * sur une periode. Il est declare au mapping, sinon le garde-fou n°10 le refuserait — et il aurait
 * raison, un index qui n'existe que dans une migration disparait a la premiere regeneration.
 *
 * Aucune reprise de donnees : la table nait vide. Les refus passes n'ont jamais ete ecrits nulle part
 * et ne se reconstituent pas — c'est precisement le defaut que cette table corrige.
 */
final class Version20260826173800 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'PAY-3 : table sale_card_rejection (trace persistee des refus de carte).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE sale_card_rejection (
                id BINARY(16) NOT NULL,
                moyen_code VARCHAR(32) NOT NULL,
                montant_centimes INT DEFAULT 0 NOT NULL,
                statut_tpe VARCHAR(16) NOT NULL,
                ref_tpe VARCHAR(64) DEFAULT NULL,
                client BINARY(16) DEFAULT NULL,
                date_heure DATETIME NOT NULL,
                vente_id BINARY(16) NOT NULL,
                point_de_vente_id BINARY(16) DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                INDEX IDX_31406F1D7DC7170A (vente_id),
                INDEX IDX_31406F1D3F95E273 (point_de_vente_id),
                INDEX IDX_31406F1DFF631228 (etablissement_id),
                INDEX idx_card_rejection_etab_date (etablissement_id, date_heure),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE sale_card_rejection ADD CONSTRAINT FK_31406F1D7DC7170A FOREIGN KEY (vente_id) REFERENCES vente_vente (id)');
        $this->addSql('ALTER TABLE sale_card_rejection ADD CONSTRAINT FK_31406F1D3F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE sale_card_rejection ADD CONSTRAINT FK_31406F1DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE sale_card_rejection');
    }
}
