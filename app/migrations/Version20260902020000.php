<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * BT-151 — la catégorie de TVA, qui n'est pas le taux.
 *
 * EN 16931 exige une catégorie (liste UNTDID 5305) en plus du taux sur chaque ligne. Aucun champ ne
 * la portait : `facturation:einvoicing:etat` ne la réclamait même pas, le terme étant déclaré
 * obligatoire dans `BusinessTerm` et jamais vérifié.
 *
 * ── ⚠ LA COLONNE EST NULLABLE, ET C'EST LE CŒUR DE CETTE MIGRATION ─────────────────────────────
 *
 * Le réflexe serait `NOT NULL DEFAULT 'S'`. Il aurait été **faux**, et la mesure le prouve : six
 * taux à 0 % existent en base, tous libellés « Hors champ (opération non commerciale) ». Hors champ,
 * c'est `O` — pas `Z` (taux zéro), pas `E` (exonéré). Les trois se ressemblent sur une facture, se
 * distinguent au contrôle fiscal, et n'appellent pas les mêmes mentions obligatoires.
 *
 * Une migration ne fabrique pas de donnée métier (D66-ter). Ces six-là restent donc **sans
 * catégorie**, et leurs factures resteront non émettables jusqu'à ce qu'un humain tranche — ce qui
 * est exactement l'état réel, dit à voix haute plutôt que masqué par un défaut plausible.
 *
 * ── CE QUI EST POSÉ, EN REVANCHE, NE SUPPOSE RIEN ──────────────────────────────────────────────
 *
 * Un taux **positif** est `S`, réduit comme normal : c'est le taux qui distingue 5,5 % de 20 %, pas
 * la catégorie. Il n'y a pas d'autre réponse possible, donc l'écrire n'est pas une supposition —
 * c'est la même nature que d'écrire `EUR` là où l'euro était déjà implicite.
 */
final class Version20260902020000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'EN 16931 : ajoute la categorie de TVA (BT-151) sur le taux, nullable, et pose S sur les taux positifs.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_taux_tva ADD vat_category VARCHAR(4) DEFAULT NULL');

        // ⚠ `taux > 0` ET RIEN D'AUTRE. Les taux nuls sont laissés vides à dessein : voir l'en-tête.
        $this->addSql("UPDATE compta_taux_tva SET vat_category = 'S' WHERE taux > 0");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE compta_taux_tva DROP vat_category');
    }
}
