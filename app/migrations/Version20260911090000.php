<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Passe `statut` et `periodicite` de l'abonnement en anglais (D5, arbitrage du 11/09).
 *
 * ── POURQUOI CE RENOMMAGE EST SÛR, ALORS QUE LE DÉPLACEMENT DE TABLE NE L'ÉTAIT PAS ───────────
 *
 * `Version20260910160000` a écarté la table `membership` neuve pour une raison mesurée : **sept
 * clés étrangères** pointent sur `sport_abonnement_fitness`, dont celle d'un échéancier SEPA en
 * fonctionnement. Déplacer les lignes obligeait à repointer les sept.
 *
 * ⚠ CE N'EST PAS LE MÊME GESTE. Vérifié : **aucune de ces clés ne porte sur `statut` ni sur
 * `periodicite`** — elles portent toutes sur des colonnes `*_id`. Réécrire une valeur dans une
 * colonne non contrainte ne touche aucune contrainte, et les lignes ne bougent pas.
 *
 * ── CE QUE LA BASE CONTIENT, MESURÉ AVANT D'ÉCRIRE ────────────────────────────────────────────
 *
 * Relevé en préproduction le 11/09, `billetterie_preprod` :
 *
 *     statut       actif x7, echu x1        (8 lignes au total)
 *     periodicite  mensuel x8
 *
 * Aucune valeur inattendue : les `CASE` ci-dessous couvrent donc tout ce qui existe. Ils couvrent
 * aussi les valeurs qu'aucune ligne ne porte aujourd'hui (`pause`, `impaye`, `resilie`,
 * `hebdomadaire`, `annuel`) — une base de développement ou une sauvegarde plus ancienne peut les
 * porter, et une migration qui ne traiterait que ce qu'elle a vu laisserait ces lignes illisibles.
 *
 * ⚠ `ELSE statut` PLUTÔT QU'UN `ELSE` FIXE. Une valeur hors énumération — il n'y en a pas, mais
 * une base tierce pourrait en porter une — est laissée TELLE QUELLE. L'écraser avec `active`
 * transformerait une donnée inconnue en abonnement actif, c'est-à-dire en accès ouvert.
 *
 * ── LE `DEFAULT` SUIT, SINON LE SCHÉMA ET L'ENTITÉ DIVERGENT ──────────────────────────────────
 *
 * La colonne porte `DEFAULT 'actif'`. Sans ce `ALTER`, une insertion sans statut explicite
 * écrirait `actif`, que `MembershipStatus::from()` refuserait à la relecture.
 */
final class Version20260911090000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Abonnement : statut et periodicite passent en anglais (D5), sans toucher aux cles etrangeres.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            UPDATE sport_abonnement_fitness SET statut = CASE statut
                WHEN 'actif'   THEN 'active'
                WHEN 'pause'   THEN 'paused'
                WHEN 'impaye'  THEN 'unpaid'
                WHEN 'resilie' THEN 'terminated'
                WHEN 'echu'    THEN 'expired'
                ELSE statut
            END
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE sport_abonnement_fitness SET periodicite = CASE periodicite
                WHEN 'mensuel'      THEN 'monthly'
                WHEN 'hebdomadaire' THEN 'weekly'
                WHEN 'annuel'       THEN 'yearly'
                ELSE periodicite
            END
            SQL);

        $this->addSql("ALTER TABLE sport_abonnement_fitness MODIFY statut VARCHAR(12) DEFAULT 'active' NOT NULL");
    }

    public function down(Schema $schema): void
    {
        $this->addSql("ALTER TABLE sport_abonnement_fitness MODIFY statut VARCHAR(12) DEFAULT 'actif' NOT NULL");

        $this->addSql(<<<'SQL'
            UPDATE sport_abonnement_fitness SET periodicite = CASE periodicite
                WHEN 'monthly' THEN 'mensuel'
                WHEN 'weekly'  THEN 'hebdomadaire'
                WHEN 'yearly'  THEN 'annuel'
                ELSE periodicite
            END
            SQL);

        $this->addSql(<<<'SQL'
            UPDATE sport_abonnement_fitness SET statut = CASE statut
                WHEN 'active'     THEN 'actif'
                WHEN 'paused'     THEN 'pause'
                WHEN 'unpaid'     THEN 'impaye'
                WHEN 'terminated' THEN 'resilie'
                WHEN 'expired'    THEN 'echu'
                ELSE statut
            END
            SQL);
    }
}
