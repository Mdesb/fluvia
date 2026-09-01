<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * REPRISE DES CRÉDITS DE CARTES (T2, seconde tranche) — trois colonnes, pas une de plus.
 *
 * Écrite à la main (D32). Aucun `DROP` que le `down()` ne défasse.
 *
 * ── `acces_droit_acces.external_ref` ET `import_batch_ref` ─────────────────────────────────────
 *
 * La spécification l'exige nommément : *« chaque crédit repris porte son externalRef et son
 * ImportBatch, pour qu'une contestation remonte au fichier d'origine »*. Sans elles, « il me restait
 * six entrées » se discute de mémoire, six semaines après la reprise, au guichet, devant la
 * personne.
 *
 * `import_batch_ref` est un identifiant nu, **pas une clé étrangère** (D2) : le module d'accès n'a
 * pas à dépendre d'un module de reprise qui ne sert qu'une fois.
 *
 * ⚠ **`external_ref` n'est pas unique ici, contrairement à `crm_client`.** Une même carte physique
 * peut être reprise, épuisée, puis rechargée sous une nouvelle référence ; et un exploitant qui
 * reprend deux sites d'enseignes différentes peut recevoir deux fichiers dont les références se
 * chevauchent. Le doublon dans UN fichier est déjà refusé par l'analyse ; forcer l'unicité en base
 * ferait échouer un second import légitime au lieu de le laisser passer.
 *
 * ── `import_batch.announced_total` ─────────────────────────────────────────────────────────────
 *
 * Le total annoncé par le client, contre lequel la somme des crédits est rapprochée avant toute
 * écriture. Nullable : les types qui ne portent pas d'argent — clients, catalogue, personnel — n'ont
 * rien à annoncer.
 */
final class Version20260901050000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Reprise des crédits de cartes : traçabilité du droit repris, et total annoncé du lot.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE acces_droit_acces ADD external_ref VARCHAR(128) DEFAULT NULL, ADD import_batch_ref BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_droit_acces_import_batch ON acces_droit_acces (import_batch_ref)');
        $this->addSql('ALTER TABLE import_batch ADD announced_total INT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE import_batch DROP announced_total');
        $this->addSql('DROP INDEX idx_droit_acces_import_batch ON acces_droit_acces');
        $this->addSql('ALTER TABLE acces_droit_acces DROP external_ref, DROP import_batch_ref');
    }
}
