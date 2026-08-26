<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D44-bis : vendre sans caisse — le point de vente passe **sur la vente**, la session devient nulle.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (14:14) et non en UTC.
 *
 * **Ce que le code seul n'aurait pas suffi à faire.** `vente_vente.session_id` était `NOT NULL` :
 * la vente sans caisse n'était pas une règle qu'on aurait pu assouplir dans `CreerVenteProcessor`,
 * elle était **impossible au niveau du schéma**. D44-bis parlait d'assouplir une règle ; le vrai
 * obstacle était une colonne.
 *
 * **Et l'invariant n'est pas perdu, il est déplacé — et renforcé.** Avant : « toute vente a une
 * session », donc un point de vente par ricochet. Après : « toute vente a un point de vente », en
 * `NOT NULL`. C'est ce dont la chaîne NF525 a réellement besoin — elle est chaînée par point de
 * vente, et `ValiderVenteService` refuse déjà de sceller sans lui. Une vente sans session est une
 * vente sans tiroir ; une vente sans point de vente serait une vente **non scellée**.
 *
 * **Reprise des données, dans cet ordre et pas un autre.** La colonne naît nullable, on la remplit
 * depuis la session de chaque vente, puis seulement on la passe `NOT NULL`. Si une seule ligne
 * restait vide, ce dernier ordre échoue et la migration s'arrête : c'est voulu. Une vente dont on ne
 * sait pas quel point de vente l'a scellée est une anomalie qu'il faut regarder, pas contourner —
 * `session_id` étant `NOT NULL` jusqu'ici, il ne devrait y en avoir aucune, et c'est justement ce que
 * cet ordre vérifie.
 *
 * **L'index unique sur le point de vente** vient du même lot : le point de vente « Vente directe » est
 * résolu par son libellé, faute de code sur l'entité. Deux points de vente homonymes dans un même
 * établissement — l'API le permet, `caisse.gerer` suffit — scinderaient une chaîne en deux moitiés
 * chacune vérifiable, l'ensemble ne l'étant plus.
 */
final class Version20260826141400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D44-bis : vente_vente.point_de_vente_id (NOT NULL, repris de la session), session_id nullable.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_vente ADD point_de_vente_id BINARY(16) DEFAULT NULL');

        // Reprise : chaque vente existante hérite du point de vente de sa session. `session_id` étant
        // `NOT NULL` avant cette migration, la jointure couvre toutes les lignes.
        $this->addSql(<<<'SQL'
            UPDATE vente_vente v
            INNER JOIN caisse_session s ON s.id = v.session_id
            SET v.point_de_vente_id = s.point_de_vente_id
            SQL);

        // Échoue si une ligne est restée vide — et c'est le contrôle, pas un risque.
        $this->addSql('ALTER TABLE vente_vente CHANGE point_de_vente_id point_de_vente_id BINARY(16) NOT NULL');
        $this->addSql('CREATE INDEX IDX_9C9B27053F95E273 ON vente_vente (point_de_vente_id)');
        $this->addSql('ALTER TABLE vente_vente ADD CONSTRAINT FK_9C9B27053F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');

        // Seulement maintenant : la session peut manquer, puisque le point de vente ne manque plus.
        $this->addSql('ALTER TABLE vente_vente CHANGE session_id session_id BINARY(16) DEFAULT NULL');

        $this->addSql('CREATE UNIQUE INDEX uniq_pdv_etablissement_libelle ON caisse_point_de_vente (etablissement_id, libelle)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_pdv_etablissement_libelle ON caisse_point_de_vente');

        // On ne redescend pas `session_id` en `NOT NULL` : s'il existe des ventes directes, elles n'ont
        // pas de session à leur rendre et l'ordre échouerait — ou pire, il faudrait leur en inventer
        // une. Une migration qui invente une session de caisse pour satisfaire une colonne écrit un
        // fait faux dans une base comptable.
        $this->addSql('ALTER TABLE vente_vente DROP FOREIGN KEY FK_9C9B27053F95E273');
        $this->addSql('DROP INDEX IDX_9C9B27053F95E273 ON vente_vente');
        $this->addSql('ALTER TABLE vente_vente DROP point_de_vente_id');
    }
}
