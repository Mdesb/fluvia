<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #363 — index (cible_type, cible_id) sur le journal d'audit.
 *
 * Écrite à la main (jamais un diff brut). Horodatée APRÈS les migrations du lot D2-B
 * (`Version20261125100000`, `Version20261125110000`, sur `feature/payfip-numcli-par-exploitant`), pour
 * passer en dernier quel que soit l'ordre des fusions ; le SQL vient de
 * `doctrine:schema:update --dump-sql`, dont seule la ligne d'`audit_entree` est gardée.
 *
 * Pourquoi : le caviardage d'une fiche anonymisée cherche ses entrées par (cible_type, cible_id).
 * Sans index, chaque fiche coûte un balayage complet de la table, et le rattrapage en traite tout
 * l'arriéré. `idx_audit_date` n'est pas touché.
 *
 * `down()` retire l'index : aucune donnée n'est perdue, seules les recherches par cible ralentissent.
 */
final class Version20261125120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#363 : idx_audit_cible (cible_type, cible_id) sur audit_entree.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_audit_cible ON audit_entree (cible_type, cible_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_audit_cible ON audit_entree');
    }
}
