<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #369 — trois index pour l'ordre des clés de scellement des chaînes NF525.
 *
 * Écrite à la main (jamais un diff brut), horodatée en UTC après la dernière migration
 * (Version20261108190000). Le SQL a été demandé à Doctrine (`doctrine:schema:update --dump-sql`) après
 * avoir déclaré les index sur les entités ; seuls ses trois `CREATE INDEX` sont repris ici (le reste de la
 * dérive qu'il propose n'est pas de ce lot).
 *
 * Pourquoi : le refus de sceller (#369) demande, pour UNE clé d'UNE chaîne, `MIN`/`MAX(numero_sequence)`.
 * Aucun index ne portait `seal_key_id` ; sans lui, il faudrait relire toute la chaîne à chaque scellement.
 * Avec `(colonnes de la chaîne, seal_key_id, numero_sequence)`, c'est une recherche d'index.
 *
 * PUREMENT ADDITIVE : aucune donnée, aucun maillon touché (la garde d'inaltérabilité n'est pas concernée,
 * c'est du DDL d'index). `down()` supprime les trois index.
 */
final class Version20261118120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '#369 : index (chaîne, seal_key_id, numero_sequence) sur les trois chaînes NF525.';
    }

    // ALGORITHM=INPLACE LOCK=NONE : les ventes, écritures et factures continuent pendant la
    // construction ; si MariaDB ne peut pas tenir cette promesse, l'ordre échoue au lieu de verrouiller
    // la table en silence (revue architecte-donnees, 18/11). Syntaxe éprouvée sur MariaDB 11.4.
    // DROP INDEX n'accepte pas ces options (refusé par MariaDB 11.4) : down() reste nu.
    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_op_pdv_key_seq ON nf525_operation_scellee (point_de_vente_id, seal_key_id, numero_sequence) ALGORITHM=INPLACE LOCK=NONE');
        $this->addSql('CREATE INDEX idx_ecriture_chain_key_seq ON compta_ecriture_comptable (profil_exploitant_id, journal_id, seal_key_id, numero_sequence) ALGORITHM=INPLACE LOCK=NONE');
        $this->addSql('CREATE INDEX idx_facture_chain_key_seq ON facturation_facture (profil_exploitant_id, seal_key_id, numero_sequence) ALGORITHM=INPLACE LOCK=NONE');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_op_pdv_key_seq ON nf525_operation_scellee');
        $this->addSql('DROP INDEX idx_ecriture_chain_key_seq ON compta_ecriture_comptable');
        $this->addSql('DROP INDEX idx_facture_chain_key_seq ON facturation_facture');
    }
}
