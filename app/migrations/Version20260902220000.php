<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE TERRITOIRE FISCAL — un code pays ne suffit pas à désigner un régime.
 *
 * ── CE QUI L'A RÉVÉLÉ ───────────────────────────────────────────────────────────────────────────
 *
 * L'import TEDB du 02/09, par une violation de contrainte :
 *
 *     Duplicate entry 'ES-standard-2026-07-01' for key 'uniq_legal_vat_rate'
 *
 * L'Espagne rend `7,00` ET `21,00` à la même date. Ce ne sont pas deux versions du même taux — ce
 * sont les Canaries et la péninsule, deux régimes sous un seul `ES`. Le référentiel n'ayant qu'une
 * place par (pays, catégorie, date), l'Espagne n'a pas pu être importée du tout : son catalogue
 * était vide.
 *
 * Le cas n'a rien d'exotique et nous concerne directement. La Corse et les DOM ont leurs propres
 * taux, Madère et les Açores les leurs, Åland les siens. Un exploitant français aux Antilles est
 * en `FR` — et il ne facture pas à 20 %.
 *
 * ── ⚠ NON NUL, ET `''` PLUTÔT QUE `NULL` : CE N'EST PAS UN DÉTAIL DE STYLE ──────────────────────
 *
 * MariaDB traite deux `NULL` comme DISTINCTS dans un index unique. Un `territory` nullable aurait
 * donc laissé coexister deux lignes « droit commun » identiques pour un même pays, une même
 * catégorie et une même date — la contrainte aurait cessé de protéger exactement le cas courant,
 * celui qu'elle protège aujourd'hui, et sans rien dire.
 *
 * Le défaut `''` explicite ce qui était déjà vrai de toutes les lignes existantes : elles relèvent
 * du droit commun de leur pays (D66-ter — la migration ne fabrique aucune donnée métier, elle
 * nomme ce qui était implicite).
 *
 * ── POURQUOI DEUX INDEX SONT DETRUITS ICI ─────────────────────────────────────────────────────
 *
 *
 * @drop-voulu : les deux index sont RECREES dans le meme up(), une ligne plus bas, avec la colonne
 *   `territory` en plus. On ne peut pas etendre un index en place : il faut le detruire et le
 *   refaire. Aucune donnee n en depend — un index ne porte rien, il accelere et il contraint.
 *
 *   `uniq_legal_vat_rate` passe de (country, category, valid_from) a (country, territory,
 *   category, valid_from) : il devient PLUS permissif, jamais moins. Les lignes qu il acceptait
 *   hier restent acceptees ; il en accepte de nouvelles, celles des territoires.
 *
 *   `idx_legal_vat_rate_country` passe de (country) a (country, territory), qui est le chemin
 *   d acces reel depuis que `inForce()` filtre sur les deux.
 *
 *
 * ── CE QUE CETTE MIGRATION NE FAIT PAS ──────────────────────────────────────────────────────────
 *
 * Elle ne pose aucun taux territorial. Les Canaries, la Corse et les DOM se sèment à la main, avec
 * leur texte légal, comme les taux français du CGI : l'export TEDB ne dit pas quel territoire porte
 * quel taux, et l'inférer ici mettrait ma connaissance à la place d'une référence.
 */
final class Version20260902220000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Territoire fiscal sur le referentiel de TVA et sur l etablissement (Canaries, Corse, DOM).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE accounting_legal_vat_rate ADD territory VARCHAR(20) DEFAULT '' NOT NULL");

        // ⚠ L'ORDRE COMPTE : la colonne doit exister avant que les index la citent.
        $this->addSql('DROP INDEX uniq_legal_vat_rate ON accounting_legal_vat_rate');
        $this->addSql('CREATE UNIQUE INDEX uniq_legal_vat_rate ON accounting_legal_vat_rate (country, territory, category, valid_from)');
        $this->addSql('DROP INDEX idx_legal_vat_rate_country ON accounting_legal_vat_rate');
        $this->addSql('CREATE INDEX idx_legal_vat_rate_country ON accounting_legal_vat_rate (country, territory)');

        $this->addSql("ALTER TABLE org_etablissement ADD fiscal_territory VARCHAR(20) DEFAULT '' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        // ⚠ REDESCENDRE PEUT ÉCHOUER, ET C'EST PRÉFÉRABLE À RÉUSSIR EN SILENCE.
        //
        // Si des taux territoriaux ont été semés, deux lignes du même pays et de la même catégorie
        // coexistent légitimement — les Canaries et la péninsule. Rétablir l'ancienne contrainte,
        // qui les confond, refusera de se créer. L'alternative serait d'en supprimer une, c'est-à-
        // dire de choisir à la place de quelqu'un quel territoire disparaît.
        $this->addSql('DROP INDEX uniq_legal_vat_rate ON accounting_legal_vat_rate');
        $this->addSql('CREATE UNIQUE INDEX uniq_legal_vat_rate ON accounting_legal_vat_rate (country, category, valid_from)');
        $this->addSql('DROP INDEX idx_legal_vat_rate_country ON accounting_legal_vat_rate');
        $this->addSql('CREATE INDEX idx_legal_vat_rate_country ON accounting_legal_vat_rate (country)');

        $this->addSql('ALTER TABLE accounting_legal_vat_rate DROP territory');
        $this->addSql('ALTER TABLE org_etablissement DROP fiscal_territory');
    }
}
