<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * #361 — identifiant de la clé de scellement sur chaque maillon des trois chaînes NF525.
 *
 * Écrite à la main (jamais un diff brut), horodatée en UTC après la dernière migration de `main`
 * (Version20261108160000).
 *
 * `seal_key_id` porte une empreinte NON RÉVERSIBLE de la clé qui a signé le maillon (16 caractères
 * hexadécimaux, `SealKeyring::keyIdOf`), jamais la clé. La vérification choisit la clé du trousseau
 * par lui.
 *
 * AUCUNE REPRISE : les maillons existants gardent `NULL`. Calculer leur identifiant demanderait la clé
 * (que la migration n'a pas, et ne doit pas avoir), et écrire sur un maillon scellé est précisément ce
 * que la garde d'inaltérabilité interdit. Un maillon sans identifiant est vérifié avec la clé active,
 * puis avec les clés retirées (compatibilité).
 *
 * `down()` retire la colonne : les maillons scellés entre-temps perdent leur identifiant, et redeviennent
 * des maillons « antérieurs au trousseau », toujours vérifiables tant que leur clé est au trousseau.
 */
final class Version20261108190000 extends AbstractMigration
{
    private const TABLES = ['nf525_operation_scellee', 'compta_ecriture_comptable', 'facturation_facture'];

    public function getDescription(): string
    {
        return '#361 : seal_key_id (identifiant non réversible de la clé de scellement) sur les trois chaînes NF525.';
    }

    public function up(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s ADD seal_key_id VARCHAR(32) DEFAULT NULL', $table));
        }
    }

    public function down(Schema $schema): void
    {
        foreach (self::TABLES as $table) {
            $this->addSql(sprintf('ALTER TABLE %s DROP seal_key_id', $table));
        }
    }
}
