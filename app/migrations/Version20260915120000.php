<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN TROISIÈME ÉTAT DE COMPLÉTUDE : « non instrumenté ».
 *
 * ── CE QUE LA COLONNE NE POUVAIT PAS PORTER ─────────────────────────────────────────────────────
 *
 * `report_mesure.statut_completude` est un `varchar(8)` : il tient `complet` et `partiel`, pas
 * `non_instrumente` (15 caractères). Selon le mode SQL, MySQL tronque ou refuse — et une valeur
 * tronquée ne se relit pas en enum PHP. La colonne passe donc à 20.
 *
 * ── POURQUOI CET ÉTAT EXISTE (arbitrage de Maxime du 15/09/2026, point n°7) ──────────────────────
 *
 * `etablissementHorsLigne()` rend `false` dans DEUX cas opposés : « les contrôleurs d'accès
 * répondent » et « il n'y a aucun contrôleur ». C'est délibéré de sa part — l'absence de tourniquet
 * n'est pas une panne de remontée (Risque §9.7 de `plan-reporting.md`) — mais l'agrégateur en
 * tirait `complet`.
 *
 * Mesuré sur la préproduction le 15/09, après le rattrapage de `reporting:agreger` :
 *
 *     sites SANS aucun contrôleur   7 sites × 11 jours   frequentation 0,00   statut COMPLET
 *     sites AVEC contrôleur         5 sites × 11 jours                        statut partiel
 *
 * Les sept comprenaient un musée et une patinoire. Un zéro certifié complet sur un site qui n'a
 * aucun moyen de compter ses visiteurs.
 *
 * ── AUCUNE DONNÉE N'EST RÉÉCRITE PAR LE `up()`, ET C'EST VOULU ──────────────────────────────────
 *
 * Cette migration élargit la colonne, rien de plus. Les 77 lignes déjà écrites en `complet` restent
 * telles quelles : c'est la prochaine exécution de `reporting:agreger` qui les corrigera, par
 * l'upsert idempotent sur `Mesure.cleAgregation`. Réécrire ici obligerait à recalculer quels sites
 * sont instrumentés en SQL, donc à dupliquer en SQL la règle qui vit dans l'agrégateur — deux
 * implémentations d'une même règle, qui divergeraient au premier changement.
 *
 * ── LE `down()` RESTAURE L'ÉTAT D'AVANT, MENSONGE COMPRIS ───────────────────────────────────────
 *
 * ⚠ ON NE PEUT PAS RÉTRÉCIR UNE COLONNE QUI CONTIENT DÉJÀ `non_instrumente`. Les lignes portant
 * cette valeur sont donc remises à `complet` — ce qu'elles valaient avant cette migration — AVANT
 * le changement de type. C'est bien la restauration de l'état antérieur, y compris son défaut : un
 * `down()` qui « améliorerait » les données ne serait plus un `down()`.
 *
 * ⚠ ET L'ORDRE COMPTE. `addSql()` est DIFFÉRÉ : les requêtes s'exécutent dans l'ordre où elles sont
 * empilées, après la sortie de la méthode. L'`UPDATE` est donc empilé avant l'`ALTER`, et c'est la
 * seule raison pour laquelle il passe.
 */
final class Version20260915120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'report_mesure.statut_completude passe à varchar(20) pour porter « non_instrumente » (arbitrage n°7 du 15/09).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE report_mesure CHANGE statut_completude statut_completude VARCHAR(20) DEFAULT \'complet\' NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // D'abord les données — sinon l'ALTER tronque ou refuse. Voir l'en-tête sur `addSql` différé.
        $this->addSql('UPDATE report_mesure SET statut_completude = \'complet\' WHERE statut_completude = \'non_instrumente\'');
        $this->addSql('ALTER TABLE report_mesure CHANGE statut_completude statut_completude VARCHAR(8) DEFAULT \'complet\' NOT NULL');
    }
}
