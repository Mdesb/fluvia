<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D57 : `nf525_daily_closure` — la clôture journalière NF525, distincte de la clôture Z.
 *
 * ⚠ Migration écrite à la main (D32), horodatée en **heure locale** (15:23) et non en UTC. DDL relevé
 * par `SHOW CREATE TABLE` sur la table que Doctrine crée depuis le mapping, noms d'index et de
 * contraintes compris.
 *
 * **Pourquoi une table de plus alors que `caisse_cloture_z` existe.** Le Z ferme une *session de
 * caisse* : il compte du liquide, constate un écart entre théorique et compté, fige un fonds. Il n'a de
 * sens que là où quelqu'un tient un tiroir. Tant que toute vente passait par une caisse, il faisait
 * office de clôture quotidienne **sans que personne ait eu à décider que c'en était une** — et un point
 * de vente de vente directe se serait retrouvé sans clôture quotidienne du tout.
 *
 * **Ce que la table porte vraiment.** `grand_total` n'est pas un chiffre de gestion : c'est le
 * mécanisme qui rend une **suppression détectable**. Chaque clôture recopie le cumul de la précédente
 * (`previous_grand_total`) et y ajoute la journée. Supprimer une vente d'hier laisserait donc le cumul
 * d'hier plus grand que la somme des ventes qui restent — l'écart se voit sans qu'on ait à savoir ce
 * qui manquait. C'est pour cette raison que le cumul ne déduit **pas** les avoirs : il totalise ce que
 * la chaîne a scellé, il ne calcule pas un résultat.
 *
 * **L'index unique n'est pas une précaution de confort.** Deux clôtures du même jour compteraient deux
 * fois la même journée dans le cumul : le mécanisme censé détecter une disparition **fabriquerait un
 * excédent**. Le contrôle existe aussi dans `DailyClosureHandler`, mais un contrôle applicatif ne
 * survit pas à deux insertions concurrentes.
 *
 * Aucune reprise de données : la table naît vide. Les journées passées ne se clôturent pas
 * rétroactivement par une migration — un arrêté est un geste daté, signé de son auteur, et en fabriquer
 * pour l'historique écrirait des faits qui n'ont pas eu lieu dans la table dont le seul rôle est de
 * prouver que rien n'a été inventé.
 */
final class Version20260826152300 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'D57 : table nf525_daily_closure (clôture journalière, distincte du Z).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE nf525_daily_closure (
                id BINARY(16) NOT NULL,
                business_day DATE NOT NULL,
                sales_count INT DEFAULT 0 NOT NULL,
                daily_total NUMERIC(12, 2) DEFAULT '0.00' NOT NULL,
                previous_grand_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                grand_total NUMERIC(14, 2) DEFAULT '0.00' NOT NULL,
                last_sequence BIGINT DEFAULT NULL,
                closed_at DATETIME NOT NULL,
                point_de_vente_id BINARY(16) NOT NULL,
                author_id BINARY(16) DEFAULT NULL,
                etablissement_id BINARY(16) NOT NULL,
                UNIQUE INDEX uniq_daily_closure_pdv_jour (point_de_vente_id, business_day),
                INDEX IDX_61876C0D3F95E273 (point_de_vente_id),
                INDEX IDX_61876C0DF675F31B (author_id),
                INDEX IDX_61876C0DFF631228 (etablissement_id),
                PRIMARY KEY (id)
            ) DEFAULT CHARACTER SET utf8mb4 ENGINE = InnoDB
            SQL);

        $this->addSql('ALTER TABLE nf525_daily_closure ADD CONSTRAINT FK_61876C0D3F95E273 FOREIGN KEY (point_de_vente_id) REFERENCES caisse_point_de_vente (id)');
        $this->addSql('ALTER TABLE nf525_daily_closure ADD CONSTRAINT FK_61876C0DF675F31B FOREIGN KEY (author_id) REFERENCES sec_utilisateur (id)');
        $this->addSql('ALTER TABLE nf525_daily_closure ADD CONSTRAINT FK_61876C0DFF631228 FOREIGN KEY (etablissement_id) REFERENCES org_etablissement (id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE nf525_daily_closure');
    }
}
