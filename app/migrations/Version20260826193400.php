<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D51 : socle partage + ajout local sur `off_type_tarif` et `off_categorie`.
 *
 * ⚠ Migration ecrite a la main (D32), horodatee en **heure locale** (19:34). DDL releve par `SHOW CREATE TABLE` sur les
 * tables que Doctrine cree depuis le mapping.
 *
 * **Ce que Maxime a demande, et qui n en fait qu un** : « un seul produit de cree, et derriere que ce
 * soit juste la tarification qui change », et « le parametrage entierement modifiable par
 * l utilisateur ». Un exploitant doit pouvoir ajouter son propre type de tarif ou sa propre categorie
 * **sans qu on lui livre une version**, et sans que son ajout apparaisse chez le voisin.
 *
 * **`portee` par defaut a `socle`, et ce n est pas un detail de confort.** D51 suggerait `null = socle`
 * sur `etablissement`. Un `null` porterait deux sens : « cette ligne appartient au socle » et
 * « personne n a encore renseigne l etablissement ». Le second arrive tout seul — un import, un
 * processeur qui oublie l estampille, une migration qui ajoute la colonne. Une ligne locale mal
 * remplie deviendrait alors du socle, **visible par tous les etablissements, en silence**. Avec le
 * discriminant, le meme oubli produit une ligne `local` sans etablissement : invisible partout, donc
 * remarquable et corrigeable. Le defaut par defaut ne fuit pas.
 *
 * **La reprise est exacte et sans perte** : toutes les lignes existantes sont du socle. Elles ont ete
 * creees quand ces tables etaient globales, donc elles le sont — le `DEFAULT 'socle'` suffit, il n y a
 * rien a deviner. C est la seule migration de ce lot ou la reprise ne demande aucun arbitrage.
 *
 * **Ce que cette migration NE fait PAS**, et qui est la suite : `off_saison` et `off_tranche_qf` sont
 * a **cloisonner** (D51), pas a doter d un socle. Leur reprise, elle, demande une decision — a quel
 * etablissement appartiennent les lignes existantes ? — et une decision ne se prend pas dans un
 * `up()`.
 */
final class Version20260826193400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D51 : portee + etablissement sur off_type_tarif et off_categorie (socle + ajout local).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE off_type_tarif
                ADD portee VARCHAR(8) DEFAULT 'socle' NOT NULL,
                ADD etablissement_id BINARY(16) DEFAULT NULL
            SQL);
        $this->addSql('CREATE INDEX IDX_6A3AC1D9FF631228 ON off_type_tarif (etablissement_id)');
        $this->addSql('ALTER TABLE off_type_tarif ADD CONSTRAINT FK_6A3AC1D9FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');

        $this->addSql(<<<'SQL'
            ALTER TABLE off_categorie
                ADD portee VARCHAR(8) DEFAULT 'socle' NOT NULL,
                ADD etablissement_id BINARY(16) DEFAULT NULL
            SQL);
        $this->addSql('CREATE INDEX IDX_BE92C325FF631228 ON off_categorie (etablissement_id)');
        $this->addSql('ALTER TABLE off_categorie ADD CONSTRAINT FK_BE92C325FF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE off_type_tarif DROP FOREIGN KEY FK_6A3AC1D9FF631228');
        $this->addSql('DROP INDEX IDX_6A3AC1D9FF631228 ON off_type_tarif');
        $this->addSql('ALTER TABLE off_type_tarif DROP portee, DROP etablissement_id');

        $this->addSql('ALTER TABLE off_categorie DROP FOREIGN KEY FK_BE92C325FF631228');
        $this->addSql('DROP INDEX IDX_BE92C325FF631228 ON off_categorie');
        $this->addSql('ALTER TABLE off_categorie DROP portee, DROP etablissement_id');
    }
}
