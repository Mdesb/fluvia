<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * SUPPRESSION DE `report_axe_analytique` — un référentiel que personne ne lisait.
 *
 * ── LA DÉCISION, ET SUR QUOI ELLE REPOSE ────────────────────────────────────────────────────────
 *
 * Arbitrage de Maxime du 15/09/2026, point n°2 de `COORDINATION/A-REVOIR.md`, ouvert le 07/09 :
 * six axes analytiques en base, alimentés par les fixtures et consommés par **rien**. Mesuré deux
 * fois, le 07/09 puis le 15/09, témoin positif à l'appui :
 *
 *     `Indicateur` dans app/src/Reporting/Service/    3 fichiers   <- témoin : la recherche VOIT
 *     `AxeAnalytique` dans app/src/Reporting/Service/ 0 fichier
 *     `axe_analytique` dans frontend/src/             0 fichier
 *
 * Ni écran, ni moteur d'agrégation, ni appel de client d'API. Un formulaire aurait laissé définir
 * des axes qui ne changent aucune analyse — c'est pour cela qu'aucun écran n'a été construit, et
 * c'est ce qui a fait ouvrir l'arbitrage plutôt que de le construire quand même.
 *
 * ── @drop-voulu : LA TABLE PART, ET SES SIX LIGNES AVEC ─────────────────────────────────────────
 *
 * Ce n'est pas une dérive à rattraper, c'est la décision. Les six lignes viennent des fixtures et
 * aucune n'a été créée par un utilisateur — vérifié : la table n'expose aucune opération
 * d'écriture atteignable depuis un écran, et son `Post` n'a jamais été appelé.
 *
 * ⚠ LA TABLE EST ISOLÉE, VÉRIFIÉ AVANT LE `DROP`. `SHOW CREATE TABLE` ne montre **aucune clé
 * étrangère**, ni entrante ni sortante : une clé primaire et l'unicité de `code`. Rien ne casse en
 * aval, et c'est mesuré, pas supposé.
 *
 * ── LE `down()` RECRÉE LA TABLE, PAS SES DONNÉES ────────────────────────────────────────────────
 *
 * Il restaure la structure telle que `SHOW CREATE TABLE` la donnait avant la suppression, sans les
 * six lignes : recharger les fixtures est le travail des fixtures, pas d'une migration. Un `down()`
 * qui réinventerait des données ferait croire à une restauration complète.
 *
 * ── ⚠ SUR LA BASE DE TEST, L'`up()` NE PEUT PAS S'EXÉCUTER EN PREMIER ───────────────────────────
 *
 * Le harnais de test monte son schéma depuis le MAPPING, pas depuis les migrations. L'entité étant
 * supprimée par ce même commit, la table n'existe pas du tout là-bas, et l'`up()` échoue sur
 * « Unknown table » — ce qui n'est pas un défaut de la migration mais une propriété du harnais.
 *
 * Joué dans cet ordre sur une base réelle, le 15/09 : `down()` crée la table (7 colonnes,
 * identiques au `SHOW CREATE TABLE` d'origine), puis `up()` la supprime. Sur la préproduction, où
 * la table existe bel et bien depuis `Version20260816162450`, l'`up()` s'exécute directement.
 */
final class Version20260915160000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime report_axe_analytique : référentiel consommé par aucun écran ni moteur (arbitrage n°2 du 15/09).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_axe_analytique');
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            CREATE TABLE report_axe_analytique (
                id BINARY(16) NOT NULL,
                code VARCHAR(30) NOT NULL,
                libelle VARCHAR(80) NOT NULL,
                type VARCHAR(20) NOT NULL,
                granularites LONGTEXT CHARACTER SET utf8mb4 COLLATE `utf8mb4_bin` DEFAULT NULL,
                est_extension TINYINT(1) DEFAULT 0 NOT NULL,
                actif TINYINT(1) DEFAULT 1 NOT NULL,
                UNIQUE INDEX uniq_axe_code (code),
                PRIMARY KEY(id)
            ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB
            SQL);
    }
}
