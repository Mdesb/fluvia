<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * « Ce produit ouvre telle ou telle zone » — la table où se pose la déclaration.
 *
 * `DroitAcces` sait depuis la migration précédente quelles zones il ouvre, et
 * `ValidationPassageHandler` s'en sert. Mais rien ne permettait de le DIRE : la règle était juste et
 * inapplicable. `ProductAccessZoneResolver` en dépose une copie sur le droit à la projection.
 *
 * ── `product_ref` EST UNE RÉFÉRENCE LIBRE, SANS CLÉ ÉTRANGÈRE (D2) ──────────────────────────────
 *
 * `App\Acces` ne dépend pas de `App\Offre`. Le produit est désigné par son identifiant seul — même
 * choix que `acces_droit_acces.produit_ref`, déjà en place. Aucune contrainte de base ne garantit
 * donc l'existence du produit, et c'est le prix assumé de l'indépendance des modules.
 *
 * ⚠ Corollaire D58 : toute requête comparant `product_ref` doit lier le paramètre AVEC son type
 * `uuid`. Sans lui, la comparaison ne trouve rien — et ne lève rien.
 *
 * ── L'UNICITÉ PORTE SUR LE COUPLE, ET C'EST ELLE QUI TIENT LA RÈGLE ─────────────────────────────
 *
 * Un produit ne déclare pas deux fois la même zone. La contrainte le dit en base plutôt que par une
 * lecture préalable, que deux requêtes simultanées franchiraient toutes les deux.
 *
 * ── LES NOMS SONT EN ANGLAIS (D5) ───────────────────────────────────────────────────────────────
 *
 * Table et colonnes neuves : `access_product_zone`, `product_ref`, `space_id`, `establishment_id`.
 * Les tables RÉFÉRENCÉES gardent leurs noms historiques français ; seules les déclarations sont
 * tenues par la règle.
 *
 * DDL relevé sur le mapping (D32), écrit à la main, horodaté en heure locale.
 */
final class Version20260829040000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Déclaration « ce produit ouvre cette zone ».';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE access_product_zone (
                id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                product_ref BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                space_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                establishment_id BINARY(16) NOT NULL COMMENT '(DC2Type:uuid)',
                INDEX IDX_access_product_zone_space (space_id),
                INDEX IDX_access_product_zone_establishment (establishment_id),
                INDEX IDX_access_product_zone_product (product_ref),
                UNIQUE INDEX uniq_product_zone (product_ref, space_id),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);

        // Pas de `ON DELETE CASCADE` sur l'espace, délibérément : une zone encore déclarée par un
        // produit ne doit pas disparaître sous ses pieds. La déclaration deviendrait muette, le
        // produit réouvrirait tout, et rien ne le dirait. Retirer une déclaration est un geste.
        $this->addSql('ALTER TABLE access_product_zone ADD CONSTRAINT FK_access_product_zone_space FOREIGN KEY (space_id) REFERENCES acces_espace_acces (id)');
        $this->addSql('ALTER TABLE access_product_zone ADD CONSTRAINT FK_access_product_zone_establishment FOREIGN KEY (establishment_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        // Redescendre supprime les déclarations : tous les produits réouvrent toutes les zones —
        // le comportement d'avant, pas une perte de donnée métier.
        $this->addSql('DROP TABLE access_product_zone');
    }
}
