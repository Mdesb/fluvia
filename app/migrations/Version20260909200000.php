<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Repare `Version20260907162600`, qui a echoue en preproduction et bloquait TOUS les deploiements.
 *
 * ⚠ CE QUE J'AI FAIT DE FAUX. J'y avais declare les colonnes d'identifiant en `CHAR(36)`. La
 * convention du depot est `BINARY(16)` : `sec_utilisateur.id`, `bou_compte_client.id` et
 * `sec_jeton_reinitialisation.id` sont tous `binary(16)`, mesure faite dans
 * `information_schema`. Une cle etrangere entre deux types differents est refusee -- MariaDB rend
 * « errno: 150 Foreign key constraint is incorrectly formed », et le message parle de « can't
 * create table » alors que c'est la CONTRAINTE qui echoue.
 *
 * ⚠ L'ETAT PARTIEL QUE CA A LAISSE, ET POURQUOI ON DOIT LE DEFAIRE. Le `CREATE TABLE` est passe ;
 * seule la contrainte a echoue. La preproduction porte donc une table `sec_email_verification_token`
 * en `CHAR(36)`, SANS cle etrangere -- et la colonne `email_verified_at` n'a jamais ete posee,
 * l'instruction suivante n'ayant pas ete atteinte. Doctrine, lui, n'a rien enregistre : la version
 * fautive n'est pas dans `doctrine_migration_versions` (mesure), donc elle serait REJOUEE a chaque
 * deploiement -- et echouerait desormais sur « table deja existante ».
 *
 * ⚠ POURQUOI UN `DROP` ET PAS UN `ALTER`. Convertir `CHAR(36)` en `BINARY(16)` demanderait de
 * reencoder chaque valeur ; la table est vide et neuve, personne n'a pu verifier une adresse avec
 * elle. La reconstruire est exact ; la convertir serait une conversion de donnees inutile, ecrite
 * une fois et jamais relue.
 *
 * ⚠ ET LA MIGRATION FAUTIVE EST SUPPRIMEE DU DEPOT, pas reecrite. Le garde-fou n°50 interdit de
 * reecrire une migration presente dans `origin/main`, parce qu'elle « a pu s'executer quelque
 * part ». Ici elle n'a tourne NULLE PART -- mais je ne veux pas d'une exception dont la
 * justification vieillirait mal. Le garde-fou lui-meme prescrit la sortie propre pour le cas
 * jumeau de la collision de noms : republier sous un numero de version LIBRE. C'est ce qui est
 * fait, et le fichier fautif disparait au lieu de rester un piege pour toute base neuve.
 */
final class Version20260909200000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Jeton de verification d adresse en BINARY(16) — repare la migration du 07/09 (errno 150).';
    }

    public function up(Schema $schema): void
    {
        // Etat partiel laisse par la migration echouee : la table existe en preproduction, avec le
        // mauvais type et sans contrainte. `IF EXISTS` rend l'instruction sans effet sur une base
        // neuve, ou la table n'a jamais ete creee.
        $this->addSql('DROP TABLE IF EXISTS sec_email_verification_token');

        $this->addSql(<<<'SQL'
            CREATE TABLE sec_email_verification_token (
                id BINARY(16) NOT NULL,
                utilisateur_id BINARY(16) NOT NULL,
                jeton VARCHAR(255) NOT NULL,
                adresse VARCHAR(180) NOT NULL,
                date_expiration DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                utilise TINYINT(1) DEFAULT 0 NOT NULL,
                date_creation DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_email_verification_jeton (jeton),
                INDEX idx_email_verification_utilisateur (utilisateur_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE sec_email_verification_token
                ADD CONSTRAINT fk_email_verification_utilisateur FOREIGN KEY (utilisateur_id)
                REFERENCES sec_utilisateur (id) ON DELETE CASCADE
        SQL);

        // ⚠ CETTE COLONNE N'AVAIT PAS ETE POSEE : l'instruction suivait la contrainte qui a echoue.
        // `email_verified_at` reste NULL pour les comptes existants -- les marquer verifies d'office
        // fabriquerait une preuve que personne n'a apportee.
        $this->addSql("ALTER TABLE bou_compte_client ADD email_verified_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE sec_email_verification_token DROP FOREIGN KEY fk_email_verification_utilisateur');
        $this->addSql('DROP TABLE sec_email_verification_token');
        $this->addSql('ALTER TABLE bou_compte_client DROP email_verified_at');
    }
}
