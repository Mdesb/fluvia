<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Cloisonnement de `Promotion` (RG-SOCLE-05) : table de liaison `off_promotion_etablissement`.
 *
 * `Promotion` ne portait **aucun** rattachement. Sa collection était donc lisible par tous les
 * exploitants de la base, y compris d'un groupe à l'autre : une promotion est une arme commerciale,
 * et elle était visible des concurrents **avant même sa date de début**. Le rattachement est calqué
 * sur `Produit::etablissements` — une promotion porte sur des produits, et un produit est
 * commercialisé site par site.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (23:30) et non en UTC. Le DDL
 * n'est pas deviné : relevé par `SHOW CREATE TABLE` sur la table que Doctrine crée depuis le mapping,
 * noms d'index et de contraintes compris.
 *
 * **La reprise de données est obligatoire, et son choix mérite d'être discuté plutôt que subi.**
 * La lecture se fait désormais par jointure interne : une promotion rattachée à zéro établissement
 * devient invisible **pour tout le monde**, y compris son auteur. Ne rien reprendre reviendrait donc
 * à faire disparaître l'existant.
 *
 * Retenu : rattacher chaque promotion existante à **tous** les établissements. Ce n'est pas un idéal,
 * c'est le seul choix qui ne détruit rien — il **préserve exactement la visibilité actuelle**, y
 * compris son excès. Autrement dit, le trou reste ouvert pour les lignes déjà là, et se referme pour
 * toutes les suivantes ; le restreindre est ensuite un geste d'exploitant, ligne par ligne, en pleine
 * connaissance. L'alternative — deviner un rattachement — aurait fabriqué de la donnée que personne
 * n'a jamais saisie.
 */
final class Version20260825233000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Cloisonnement de Promotion : table off_promotion_etablissement + reprise (visibilité actuelle préservée).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE off_promotion_etablissement (
                promotion_id BINARY(16) NOT NULL,
                etablissement_id BINARY(16) NOT NULL,
                INDEX IDX_71FEF9F6139DF194 (promotion_id),
                INDEX IDX_71FEF9F6FF631228 (etablissement_id),
                PRIMARY KEY (promotion_id, etablissement_id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE off_promotion_etablissement
                ADD CONSTRAINT FK_71FEF9F6139DF194 FOREIGN KEY (promotion_id)
                    REFERENCES off_promotion (id) ON DELETE CASCADE
            SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE off_promotion_etablissement
                ADD CONSTRAINT FK_71FEF9F6FF631228 FOREIGN KEY (etablissement_id)
                    REFERENCES org_etablissement (id) ON DELETE CASCADE
            SQL);

        // Reprise : voir l'en-tête. `INSERT IGNORE` pour rester rejouable sur une base où la table
        // aurait déjà été alimentée par un `schema:create`.
        $this->addSql(<<<'SQL'
            INSERT IGNORE INTO off_promotion_etablissement (promotion_id, etablissement_id)
            SELECT p.id, e.id FROM off_promotion p CROSS JOIN org_etablissement e
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE off_promotion_etablissement');
    }
}
