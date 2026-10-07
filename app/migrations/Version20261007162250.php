<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Clé d'idempotence du règlement, unique sur toute la table (07/10/2026, ticket opposable, lot 1).
 *
 * Un règlement rejoué (réponse perdue, client qui réessaie) encaissait deux fois : rien ne le
 * reconnaissait. `PaiementHandler` cherche désormais la clé avant tout débit ; l'index unique
 * garantit qu'il n'existera jamais deux règlements pour une même clé.
 *
 * Portée : la TABLE, pas la vente — comme l'`id` fourni, qui vaut clé et qui est une clé primaire
 * (spec G-1, contradiction C-6). La migration homonyme de #50 (`Version20260908083000`) portait le
 * couple (vente, clé) et un horodatage antérieur à `main` : elle n'est pas reprise.
 *
 * Colonne nullable, sans valeur pour l'existant : les règlements passés n'ont jamais été rejouables,
 * et leur en inventer une reviendrait à écrire dans des lignes append-only (NF525). MariaDB admet
 * plusieurs NULL dans un index unique : mesuré le 07/10 en jouant cette migration (up, down, up) sur
 * une copie de la sauvegarde de préprod, dont les 55 règlements n'ont aucune clé.
 *
 * SQL demandé à Doctrine (`doctrine:schema:update --dump-sql`) puis recopié, uuid en BINARY(16).
 */
final class Version20261007162250 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Règlement : clé d\'idempotence, unique sur toute la table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_paiement ADD cle_idempotence BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_paiement_cle_idempotence ON vente_paiement (cle_idempotence)');
    }

    public function down(Schema $schema): void
    {
        // Le retour arrière efface les clés posées depuis : les règlements restent, seule leur
        // protection contre le rejeu disparaît. Rien d'autre ne lit cette colonne.
        $this->addSql('DROP INDEX uniq_paiement_cle_idempotence ON vente_paiement');
        $this->addSql('ALTER TABLE vente_paiement DROP cle_idempotence');
    }
}
