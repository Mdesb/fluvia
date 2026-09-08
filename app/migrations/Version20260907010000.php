<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE REFERENTIEL DES METIERS : deux tables, vides.
 *
 * Etape 2 du plan `features/referentiel-metiers`. Maxime, le 06/09 : « il y a plus de metiers que
 * ca et la liste va continuer de s'allonger ».
 *
 * ── CE QUE CES DEUX TABLES REMPLACENT ──────────────────────────────────────────────────────────
 *
 * Ajouter un metier demande aujourd'hui de toucher NEUF endroits, et SEPT de ces oublis n'emettent
 * aucun signal : sans case dans l'enumeration, la structure du client s'ouvre avec zero module ;
 * sans prereglage, un `?? []` lui en donne deux sur quinze et le test cense le voir reste vert ;
 * sans nom, sa page de vente repond 200 avec un `<title>` vide.
 *
 * ── ⚠ ELLES SONT VIDES, ET C'EST D66-ter ───────────────────────────────────────────────────────
 *
 * « Une migration ne fabrique jamais de donnee metier. Ce qui manque reste visiblement manquant. »
 * Les cinq metiers existants seront materialises par une COMMANDE idempotente (etape 3), pas ici.
 * Une migration qui semerait ces lignes les recreerait a chaque environnement neuf, y compris celles
 * qu'un exploitant aurait deliberement retirees.
 *
 * ── ⚠ ECRITE A LA MAIN, MAIS PAS DEVINEE ───────────────────────────────────────────────────────
 *
 * `migrations:diff` est interdit ici : il ratisse la derive des autres sessions et horodate en UTC.
 * Le SQL ci-dessous a donc ete DEMANDE a Doctrine (`doctrine:schema:update --dump-sql`) puis recopie,
 * noms d'index compris — `FK_4E11D334C2D9760` et son `IDX_` jumeau sont ceux que Doctrine genere.
 * Les renommer « plus lisiblement » ajouterait deux lignes de derive permanente a un depot qui en
 * tolere deja une centaine, et la prochaine personne qui cherchera une vraie divergence les lirait,
 * les jugerait inoffensives, et prendrait l'habitude de sauter ce que la commande affiche.
 *
 * ⚠ `lead` est un `LONGTEXT` et non un `TEXT` : c'est ce que Doctrine produit pour `type: 'text'`.
 * Ecrire `TEXT` aurait suffi a creer un ecart a chaque `--dump-sql` a venir.
 *
 * Additif et reversible : deux tables neuves, aucune touchee.
 */
final class Version20260907010000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Referentiel des metiers : la table des metiers et celle de leurs activites, vides.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE website_trade (
                id BINARY(16) NOT NULL,
                code VARCHAR(64) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                name VARCHAR(160) NOT NULL,
                search_title VARCHAR(200) NOT NULL,
                lead LONGTEXT NOT NULL,
                position SMALLINT DEFAULT 0 NOT NULL,
                status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                INDEX idx_website_trade_publication (status, position),
                UNIQUE INDEX uniq_website_trade_code (code),
                UNIQUE INDEX uniq_website_trade_slug (slug),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE website_trade_activity (
                id BINARY(16) NOT NULL,
                trade_id BINARY(16) NOT NULL,
                activity VARCHAR(32) NOT NULL,
                position SMALLINT DEFAULT 0 NOT NULL,
                INDEX IDX_4E11D334C2D9760 (trade_id),
                INDEX idx_website_trade_activity_type (activity),
                UNIQUE INDEX uniq_website_trade_activity (trade_id, activity),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        /*
         * ⚠ CASCADE, contrairement aux autres liens du module. Une activite n'a aucune existence
         *   hors de son metier : la ligne orpheline ne serait lisible par personne, et elle ferait
         *   compter une activite a un metier disparu. Le comportement est declare AUSSI dans le
         *   mapping (`onDelete: 'CASCADE'`) — une regle qui ne vivrait que dans la migration serait
         *   une regle que personne ne lit, et que Doctrine proposerait d'effacer au premier
         *   `schema:update`.
         */
        $this->addSql('ALTER TABLE website_trade_activity ADD CONSTRAINT FK_4E11D334C2D9760 FOREIGN KEY (trade_id) REFERENCES website_trade (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE website_trade_activity DROP FOREIGN KEY FK_4E11D334C2D9760');
        $this->addSql('DROP TABLE website_trade_activity');
        $this->addSql('DROP TABLE website_trade');
    }
}
