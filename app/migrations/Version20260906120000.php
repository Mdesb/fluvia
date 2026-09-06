<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `compta_vente_impayee_regie` : le règlement d'une vente marquée impayée.
 *
 * Marquer une vente « impayée régie » était un aller sans retour — ni `Delete`, ni annulation, ni
 * règlement. Le coût n'était pas l'ergonomie : `GenerateurEReportingHandler` exclut de la
 * déclaration DGFiP toutes les ventes marquées, par un `findAll()` sans statut. Un chèque finalement
 * encaissé restait donc exclu POUR TOUJOURS.
 *
 * ── ÉCRITE À PARTIR DE CE QUE DOCTRINE DEMANDE, PAS DE MÉMOIRE ─────────────────────────────────
 *
 * ⚠ `Version20260906100000` a été écrite « au style d'un autre dépôt » et a laissé deux lignes de
 * dérive : des `COMMENT '(DC2Type:…)'` que ce projet ne pose pas, et un nom d'index lisible que
 * Doctrine voulait renommer à perpétuité. Il a fallu une migration de plus pour les retirer.
 *
 * Celle-ci recopie mot pour mot la sortie de
 * `doctrine:schema:update --dump-sql | grep compta_vente_impayee_regie`, exécutée sur le mapping
 * neuf avant d'écrire une seule ligne — `--dump-sql` n'exécute rien, il dit ce qu'il faudrait faire.
 * Les noms `FK_7287EF26BE258924` / `IDX_7287EF26BE258924` sont donc ceux de Doctrine, et le schéma
 * ne dérive pas.
 *
 * La règle reste : jamais `migrations:diff`, qui ratisserait la dérive des autres sessions ouvertes.
 */
final class Version20260906120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'compta_vente_impayee_regie : règlement (date, motif, auteur) d\'une vente marquée impayée.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE compta_vente_impayee_regie
                ADD regle_le DATETIME DEFAULT NULL,
                ADD motif_reglement VARCHAR(200) DEFAULT NULL,
                ADD regle_par_utilisateur_id BINARY(16) DEFAULT NULL
        SQL);

        $this->addSql(<<<'SQL'
            ALTER TABLE compta_vente_impayee_regie
                ADD CONSTRAINT FK_7287EF26BE258924
                FOREIGN KEY (regle_par_utilisateur_id) REFERENCES sec_utilisateur (id) ON DELETE SET NULL
        SQL);

        $this->addSql('CREATE INDEX IDX_7287EF26BE258924 ON compta_vente_impayee_regie (regle_par_utilisateur_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_vente_impayee_regie DROP FOREIGN KEY FK_7287EF26BE258924');
        $this->addSql('DROP INDEX IDX_7287EF26BE258924 ON compta_vente_impayee_regie');
        $this->addSql(<<<'SQL'
            ALTER TABLE compta_vente_impayee_regie
                DROP regle_le,
                DROP motif_reglement,
                DROP regle_par_utilisateur_id
        SQL);
    }
}
