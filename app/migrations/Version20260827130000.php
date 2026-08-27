<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Les contacts d'un client professionnel — avec leur fonction.
 *
 * **Ce que `crm_beneficiaire` ne pouvait pas dire.** Il porte un rôle *payeur* / *bénéficiaire* :
 * une sémantique de famille — qui paie l'abonnement, qui entre à la piscine. Elle ne sait pas dire
 * « directrice », « comptabilité », « celui qui signe ».
 *
 * Une société avec un seul interlocuteur nommé dans un champ texte est ce qui fait perdre un client
 * le jour où cette personne part : le devis attend une signature de quelqu'un qui n'est plus là, la
 * facture arrive dans une boîte fermée.
 *
 * > **Une entreprise n'est pas une personne : c'est plusieurs personnes qui n'ont pas le même rôle,
 * > et se tromper de rôle coûte une facture impayée.**
 *
 * ---
 *
 * **`primary_for_customer_id` MÉRITE UNE EXPLICATION, PARCE QUE SA FORME EST SURPRENANTE.**
 *
 * La règle est « un seul contact principal par client ». MySQL ne connaît pas l'index unique partiel :
 * on ne peut pas déclarer « unique là où `primary_contact` est vrai ».
 *
 * Cette colonne porte donc l'identifiant du client **quand** le contact est principal, et `NULL`
 * sinon. MySQL ignorant les `NULL` dans un index unique, `uniq_customer_contact_primary` rend la règle
 * indéfectible.
 *
 * Tenir cette règle en PHP la laisserait tomber au premier import, au premier script, à la première
 * requête concurrente — **et deux contacts principaux ne se voient pas : on écrit simplement au
 * mauvais.**
 *
 * DDL relevé par `doctrine:schema:update --dump-sql` sur le mapping (D32).
 */
final class Version20260827130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Les contacts d’un client professionnel, avec leur fonction.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(
            'CREATE TABLE crm_customer_contact (
                id BINARY(16) NOT NULL,
                last_name VARCHAR(120) NOT NULL,
                first_name VARCHAR(120) DEFAULT NULL,
                job_title VARCHAR(120) DEFAULT NULL,
                email VARCHAR(180) DEFAULT NULL,
                phone VARCHAR(40) DEFAULT NULL,
                note LONGTEXT DEFAULT NULL,
                primary_contact TINYINT DEFAULT 0 NOT NULL,
                created_at DATETIME NOT NULL,
                customer_id BINARY(16) NOT NULL,
                primary_for_customer_id BINARY(16) DEFAULT NULL,
                INDEX idx_customer_contact_customer (customer_id),
                UNIQUE INDEX uniq_customer_contact_primary (primary_for_customer_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4'
        );

        $this->addSql(
            'ALTER TABLE crm_customer_contact
             ADD CONSTRAINT FK_A7F340A99395C3F3 FOREIGN KEY (customer_id) REFERENCES crm_client (id)'
        );
        $this->addSql(
            'ALTER TABLE crm_customer_contact
             ADD CONSTRAINT FK_A7F340A914644528 FOREIGN KEY (primary_for_customer_id) REFERENCES crm_client (id)'
        );
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_customer_contact DROP FOREIGN KEY FK_A7F340A914644528');
        $this->addSql('ALTER TABLE crm_customer_contact DROP FOREIGN KEY FK_A7F340A99395C3F3');
        $this->addSql('DROP TABLE crm_customer_contact');
    }
}
