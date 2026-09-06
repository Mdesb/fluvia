<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * L'API PUBLIQUE : UN TIERS PEUT EXISTER, RECEVOIR UNE CLE, ET ETRE AUTORISE PAR UN ETABLISSEMENT.
 *
 * Premiere tranche de T1 (D101 « le dehors », D102 « l'API passe en premier »). Arbitrage de Maxime
 * du 06/09 : la surface publique est **restreinte et versionnee**, pas l'API interne rendue
 * publique — les 922 routes internes restent internes et libres de bouger.
 *
 * ── TROIS TABLES, ET LA RAISON DE LEUR SEPARATION ───────────────────────────────────────────────
 *
 * `public_api_application` — la societe qui s'integre. ⚠ **Elle ne porte AUCUN etablissement**, et
 * c'est ce qui la distingue d'un jeton de borne : un agregateur sert plusieurs clients, c'est meme
 * tout son interet. Lui coller un etablissement obligerait a creer une application par client.
 *
 * `public_api_credential` — la cle. `secret_hash` = `hash('sha256', $secret)`, recherche exacte
 * indexee, **pas** bcrypt : meme raisonnement que `acces_jeton_terminal`, le secret a une forte
 * entropie native (32 octets tires par le serveur). `prefix` n'est pas un morceau utilisable du
 * secret, c'est un NOM : sans lui, une application portant trois cles n'a aucun moyen de designer
 * celle qui a fuite, puisque le secret n'est affiche qu'une fois.
 *
 * `public_api_grant` — le consentement. ⚠ **C'EST ICI, ET NULLE PART AILLEURS, QUE LE CLOISONNEMENT
 * DE L'API PUBLIQUE SE JOUE.** Une cle dit « je suis l'application X » ; elle ne dit pas a quelles
 * donnees X a droit. Si l'acces etait porte par la cle, une cle delivree une fois vaudrait pour tous
 * les etablissements du reseau — et rien ne le signalerait, puisque la requete serait parfaitement
 * authentifiee. C'est le defaut qu'aucun test d'authentification ne trouve.
 *
 * ── CE QUE CE LOT NE FAIT PAS ───────────────────────────────────────────────────────────────────
 *
 * Aucune donnee metier n'est exposee. `/v1` authentifie, et c'est tout. Ouvrir des donnees dans le
 * meme lot que le mecanisme cense les proteger interdirait de prouver l'un ou l'autre.
 *
 * Additif et reversible : trois tables neuves, aucune touchee.
 */
final class Version20260906010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'API publique : application tierce, cle, et consentement par etablissement.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE public_api_application (
                id BINARY(16) NOT NULL,
                name VARCHAR(120) NOT NULL,
                contact_email VARCHAR(180) NOT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                active TINYINT(1) DEFAULT 1 NOT NULL,
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE public_api_credential (
                id BINARY(16) NOT NULL,
                application_id BINARY(16) NOT NULL,
                secret_hash VARCHAR(64) NOT NULL,
                prefix VARCHAR(16) NOT NULL,
                issued_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                expires_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                last_used_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                status VARCHAR(12) DEFAULT 'active' NOT NULL,
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                revoked_by_id BINARY(16) DEFAULT NULL,
                UNIQUE INDEX uniq_public_api_secret_hash (secret_hash),
                INDEX idx_public_api_credential_application (application_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE public_api_grant (
                id BINARY(16) NOT NULL,
                application_id BINARY(16) NOT NULL,
                etablissement_id BINARY(16) NOT NULL,
                scopes JSON NOT NULL,
                status VARCHAR(12) DEFAULT 'active' NOT NULL,
                granted_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                granted_by_id BINARY(16) DEFAULT NULL,
                revoked_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                INDEX idx_public_api_grant_application (application_id),
                INDEX idx_public_api_grant_etablissement (etablissement_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        /*
         * ⚠ RESTRICT SUR L'APPLICATION, PAS CASCADE. Supprimer une application dont des cles vivent
         *   ferait disparaitre en silence des acces actifs. On veut que la suppression ECHOUE tant
         *   qu'une cle ou un consentement subsiste : la desactivation est le geste normal, la
         *   suppression est un geste rare qu'on doit voir refuser.
         */
        $this->addSql('ALTER TABLE public_api_credential ADD CONSTRAINT fk_public_api_credential_application FOREIGN KEY (application_id) REFERENCES public_api_application (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE public_api_credential ADD CONSTRAINT fk_public_api_credential_revoked_by FOREIGN KEY (revoked_by_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE public_api_grant ADD CONSTRAINT fk_public_api_grant_application FOREIGN KEY (application_id) REFERENCES public_api_application (id) ON DELETE RESTRICT');
        $this->addSql('ALTER TABLE public_api_grant ADD CONSTRAINT fk_public_api_grant_etablissement FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE public_api_grant ADD CONSTRAINT fk_public_api_grant_granted_by FOREIGN KEY (granted_by_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE public_api_grant DROP FOREIGN KEY fk_public_api_grant_granted_by');
        $this->addSql('ALTER TABLE public_api_grant DROP FOREIGN KEY fk_public_api_grant_etablissement');
        $this->addSql('ALTER TABLE public_api_grant DROP FOREIGN KEY fk_public_api_grant_application');
        $this->addSql('ALTER TABLE public_api_credential DROP FOREIGN KEY fk_public_api_credential_revoked_by');
        $this->addSql('ALTER TABLE public_api_credential DROP FOREIGN KEY fk_public_api_credential_application');
        $this->addSql('DROP TABLE public_api_grant');
        $this->addSql('DROP TABLE public_api_credential');
        $this->addSql('DROP TABLE public_api_application');
    }
}
