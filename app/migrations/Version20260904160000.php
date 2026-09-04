<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Le site public de l'éditeur : blog et blocs de la page d'accueil (ED-10).
 *
 * ⚠ **AUCUNE DE CES TROIS TABLES NE PORTE D'ÉTABLISSEMENT, ET C'EST VOULU.** Elles décrivent le site
 * de **l'éditeur** — celui qui vend la plateforme — pas les données d'un client. Une colonne
 * `etablissement_id` ferait croire à un cloisonnement, et la question « quel établissement lit cet
 * article ? » n'a pas de réponse : le lecteur est un inconnu sans compte. Ce qui protège l'écriture,
 * c'est la garde `EditorOnly` sur `/editor/website/**`, pas une colonne.
 *
 * ⚠ **`ON DELETE SET NULL` SUR LA RUBRIQUE, JAMAIS `CASCADE`.** Supprimer une rubrique doit laisser
 * ses articles en place, sans rubrique. Une cascade ferait disparaître des pages **publiées** — donc
 * indexées, partagées, liées ailleurs — d'un clic dans un écran d'administration, sans que rien
 * n'annonce que douze articles partaient avec.
 *
 * ⚠ **SÛRE PENDANT LE DÉPLOIEMENT** : `deploy-preprod.sh` applique les migrations AVANT de redémarrer
 * FPM. Entre les deux, le schéma est neuf et le code est ancien. Trois tables neuves qu'aucun code
 * ancien n'interroge traversent cette fenêtre sans rien casser.
 */
final class Version20260904160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Crée le blog et les blocs de contenu du site vitrine de l’éditeur (ED-10).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE website_blog_category (
                id BINARY(16) NOT NULL,
                slug VARCHAR(120) NOT NULL,
                name VARCHAR(120) NOT NULL,
                description LONGTEXT DEFAULT NULL,
                UNIQUE INDEX uniq_website_blog_category_slug (slug),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE website_blog_post (
                id BINARY(16) NOT NULL,
                category_id BINARY(16) DEFAULT NULL,
                slug VARCHAR(160) NOT NULL,
                title VARCHAR(200) NOT NULL,
                excerpt LONGTEXT NOT NULL,
                body LONGTEXT NOT NULL,
                cover_url VARCHAR(500) DEFAULT NULL,
                cover_alt VARCHAR(200) DEFAULT NULL,
                status VARCHAR(16) DEFAULT 'draft' NOT NULL,
                published_at DATETIME DEFAULT NULL COMMENT '(DC2Type:datetime_immutable)',
                author_name VARCHAR(120) DEFAULT NULL,
                meta_description VARCHAR(300) DEFAULT NULL,
                created_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                UNIQUE INDEX uniq_website_blog_post_slug (slug),
                INDEX idx_website_blog_post_publication (status, published_at),
                INDEX IDX_WEBSITE_BLOG_POST_CATEGORY (category_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE website_blog_post
                ADD CONSTRAINT FK_WEBSITE_BLOG_POST_CATEGORY FOREIGN KEY (category_id)
                REFERENCES website_blog_category (id) ON DELETE SET NULL
            SQL);

        $this->addSql(<<<'SQL'
            CREATE TABLE website_content_block (
                block_key VARCHAR(80) NOT NULL,
                block_value JSON NOT NULL,
                updated_at DATETIME NOT NULL COMMENT '(DC2Type:datetime_immutable)',
                PRIMARY KEY(block_key)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : les trois tables créées par ce up(), et rien d'autre.
        //   ⚠ Redescendre EFFACE LE CONTENU DU SITE : les articles publiés, leurs rubriques et le
        //   texte de la page d'accueil. Rien ne les régénère — `website:blocks:seed` ne réécrit que
        //   les blocs, avec le texte d'origine, et n'a jamais connu les articles. À ne redescendre
        //   que sur une base dont on accepte de perdre le site.
        $this->addSql('ALTER TABLE website_blog_post DROP FOREIGN KEY FK_WEBSITE_BLOG_POST_CATEGORY');
        $this->addSql('DROP TABLE website_blog_post');
        $this->addSql('DROP TABLE website_blog_category');
        $this->addSql('DROP TABLE website_content_block');
    }
}
