<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `vente_paiement` : la clé d'idempotence du RÈGLEMENT, qui n'existait que sur la vente.
 *
 * Ouvrir deux fois le même panier ne créait qu'une vente (`uniq_vente_cle_idempotence`). Encaisser
 * deux fois le même règlement en créait deux — alors que c'est le règlement qui déplace l'argent : le
 * débit du porte-monnaie et l'ordre au TPE partent avant qu'une seule ligne soit écrite.
 *
 * ⚠ **La contrainte porte le COUPLE (vente, clé), pas la clé seule.** La garde applicative cherche
 * dans les règlements de la vente ; une contrainte globale interdirait davantage que ce qu'elle
 * cherche, et une clé réemployée sur une autre vente passerait la recherche pour se faire refuser au
 * `flush()` — c'est-à-dire après le débit de la carte. Les deux portées sont volontairement égales.
 *
 * Nullable : les règlements antérieurs n'en portent aucune, et sous MySQL comme sous PostgreSQL une
 * contrainte d'unicité admet plusieurs NULL — l'historique ne se gêne donc pas lui-même. Leur donner
 * une clé inventée aurait exigé d'écrire dans des lignes que `InalterabiliteListener` protège.
 *
 * Écrite à la main d'après le mapping, jamais par `doctrine:migrations:diff` : le diff ratisserait la
 * dérive des autres sessions en vol et l'embarquerait sous ce message.
 *
 * ⚠ **`BINARY(16)`, et je l'avais d'abord écrit `CHAR(36)`.** C'est la forme que Doctrine produit par
 * défaut pour un uuid, et elle est fausse ici : tout le dépôt stocke les uuid en binaire — `id` et
 * `vente_id` de cette table même, `vente_vente.cle_idempotence`, `acces_passage.cle_idempotence`. La
 * suite de tests ne pouvait pas l'attraper : le harnais monte le schéma depuis le MAPPING
 * (`schema:create`), jamais depuis les migrations, donc quatre tests verts ne disaient rien du
 * fichier que voici. Trouvé en lisant le précédent, pas en exécutant.
 */
final class Version20260908083000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vente_paiement : cle_idempotence nullable + unicite (vente_id, cle_idempotence).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_paiement ADD cle_idempotence BINARY(16) DEFAULT NULL');
        $this->addSql('CREATE UNIQUE INDEX uniq_paiement_vente_cle ON vente_paiement (vente_id, cle_idempotence)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX uniq_paiement_vente_cle ON vente_paiement');
        $this->addSql('ALTER TABLE vente_paiement DROP cle_idempotence');
    }
}
