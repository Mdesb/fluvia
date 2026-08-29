<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Factures d'acompte : le lien vers la facture de solde qu'elles viendront diminuer.
 *
 * Demandé par Maxime — rien n'existait dans le dépôt, aucune occurrence d'« acompte ».
 *
 * ── LE LIEN EST PORTÉ PAR L'ACOMPTE, PAS PAR LE SOLDE ───────────────────────────────────────────
 *
 * Un solde peut avoir plusieurs acomptes, un acompte n'a qu'un solde : le sens de la relation suit
 * la cardinalité, pas l'ordre chronologique. La colonne est donc sur la facture d'acompte, nulle
 * partout ailleurs.
 *
 * ── PAS DE `ON DELETE CASCADE`, ET C'EST IMPORTANT ──────────────────────────────────────────────
 *
 * Une facture émise ne se supprime pas — elle est numérotée, scellée et chaînée (NF525). La clé
 * étrangère est donc en RESTRICT implicite : si un jour quelqu'un tentait d'effacer un solde, la
 * base refuserait plutôt que d'orpheliner ses acomptes.
 *
 * ── AUCUNE NOUVELLE SÉRIE DE NUMÉROTATION ───────────────────────────────────────────────────────
 *
 * L'acompte prend la série des factures. Une série dédiée devrait être créée par quelqu'un, et un
 * établissement neuf n'en aurait pas — c'est exactement le genre d'objet manquant qui rend un
 * mécanisme inatteignable, et on en a corrigé plusieurs cette semaine.
 *
 * DDL relevé sur le mapping (D32), écrit à la main.
 */
final class Version20260829160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Facture d’acompte : lien vers la facture de solde.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE facturation_facture ADD facture_soldee_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE facturation_facture ADD CONSTRAINT FK_facture_soldee FOREIGN KEY (facture_soldee_id) REFERENCES facturation_facture (id)');
        $this->addSql('CREATE INDEX IDX_facture_soldee ON facturation_facture (facture_soldee_id)');
    }

    public function down(Schema $schema): void
    {
        // ⚠ Redescendre perd le RATTACHEMENT des acomptes à leur solde, pas les factures : les
        // acomptes déjà émis restent, numérotés et scellés, et les lignes de déduction déjà posées
        // sur les soldes restent elles aussi. L'équilibre comptable est préservé ; c'est la
        // possibilité de déduire les acomptes À VENIR qui disparaît.
        $this->addSql('ALTER TABLE facturation_facture DROP FOREIGN KEY FK_facture_soldee');
        $this->addSql('DROP INDEX IDX_facture_soldee ON facturation_facture');
        $this->addSql('ALTER TABLE facturation_facture DROP facture_soldee_id');
    }
}
