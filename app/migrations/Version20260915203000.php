<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * `report_export.statut` passe de VARCHAR(8) à VARCHAR(16) — sans quoi `non_expedie` est TRONQUÉ.
 *
 * ── LE DÉFAUT QUE CETTE MIGRATION EXISTE POUR ÉVITER ────────────────────────────────────────────
 *
 * `StatutExport::NonExpedie` vaut `non_expedie` : 11 caractères dans une colonne de 8. MySQL en
 * mode non strict tronquerait à `non_expe`, que `StatutExport::from()` refuserait à la relecture —
 * l'écriture passe, la lecture casse, et le rapport devient illisible au lieu d'être honnête.
 *
 * ⚠ C'EST LE MÊME DÉFAUT QU'UNE SEMAINE PLUS TÔT, sur `report_mesure.statut_completude`
 * (`Version20260915120000`, `length: 8` pour `non_instrumente`). Deux enums du même module, même
 * longueur d'origine, même oubli : ce n'est pas un accident mais une propriété de la façon dont ces
 * colonnes ont été déclarées. 16 reprend la largeur que `Compta\Entity\ExportComptable` utilise
 * déjà pour le même genre de valeur.
 *
 * ── LE `down()` REMET 8, ET RAMÈNE LES LIGNES DANS LES TROIS ÉTATS D'ORIGINE ─────────────────────
 *
 * L'ordre compte, et il est contre-intuitif : `addSql()` est DIFFÉRÉ, donc tout ce qui est empilé
 * s'exécute dans l'ordre d'empilement — l'`UPDATE` doit être empilé AVANT le rétrécissement, sinon
 * il tourne sur une colonne déjà trop courte.
 *
 * Les lignes `non_expedie` redeviennent `genere`, et c'est le choix juste : `genere` dit « le
 * fichier existe, rien n'affirme qu'il a été envoyé », ce qui est exactement vrai de ces lignes.
 * Les passer à `envoye` fabriquerait le mensonge que tout ce travail sert à empêcher.
 */
final class Version20260915203000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'report_export.statut en VARCHAR(16) : `non_expedie` (11 car.) ne tient pas dans 8.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_export MODIFY statut VARCHAR(16) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // Empilé AVANT le rétrécissement : `addSql` est différé, l'ordre d'empilement est l'ordre
        // d'exécution. L'inverse tournerait sur une colonne de 8 et tronquerait la comparaison.
        $this->addSql("UPDATE report_export SET statut = 'genere' WHERE statut = 'non_expedie'");
        $this->addSql('ALTER TABLE report_export MODIFY statut VARCHAR(8) NOT NULL');
    }
}
