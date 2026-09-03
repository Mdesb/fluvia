<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN TERRAIN QUI SE DÉCLINE — R13 et R14.
 *
 * ── LA DÉCISION ─────────────────────────────────────────────────────────────────────────────────
 *
 * Maxime a tranché : padel, tennis, squash et badminton relèvent d'**une seule verticale**. Un
 * terrain, un créneau, une grille tarifaire par plage horaire et par statut de joueur — la
 * mécanique est identique. Ce qui change tient dans deux attributs : le sport, et la surface.
 *
 * ⚠ CE QUE CETTE MIGRATION NE FAIT PAS : elle ne renomme ni la table `padel_terrain`, ni l'entité,
 * ni la route. Un renommage toucherait la table, les routes, les groupes de sérialisation, l'écran
 * et les tests qui empruntent la route — pour zéro gain fonctionnel aujourd'hui. Les attributs
 * rendent le terrain déclinable tout de suite ; le renommage sera mécanique le jour où un club
 * non-padel sera signé.
 *
 * ── ⚠ POURQUOI L'UNE A UN DÉFAUT ET L'AUTRE NON (D66-ter) ───────────────────────────────────────
 *
 *   sport      DEFAULT 'padel'. Les lignes existantes vivent dans une table `padel_terrain`, sur un
 *              module padel, dans un club de padel. Écrire `padel` ne fabrique aucune donnée
 *              métier : c'est le constat, pas une supposition.
 *
 *   surface    NULL. Personne n'a jamais relevé la surface des terrains existants. Poser « résine »
 *              par défaut inventerait un fait qu'aucun relevé n'a constaté — exactement ce que
 *              D66-ter interdit. `null` dit « on ne sait pas », et c'est la vérité.
 *
 * ⚠ LE `DEFAULT` EST DÉCLARÉ AU MAPPING (D32, garde-fou n°10) : `TerrainPadel::$sport` porte
 * `options: ['default' => 'padel']`. Sans cette déclaration, un `migrations:diff` ultérieur croirait
 * la colonne dérivée et proposerait de la « corriger » — c'est le piège du 25/08.
 *
 * ⚠ SÛRE PENDANT LE DÉPLOIEMENT : `deploy-preprod.sh` applique les migrations AVANT de redémarrer
 * FPM. Entre les deux, le schéma est neuf et le code est ancien. Une colonne nullable et une
 * colonne à défaut traversent cette fenêtre sans rien casser.
 */
final class Version20260904060000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute padel_terrain.sport et .surface : un terrain réservable qui se décline (R13, R14).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE padel_terrain ADD sport VARCHAR(20) DEFAULT 'padel' NOT NULL");
        $this->addSql('ALTER TABLE padel_terrain ADD surface VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : les deux colonnes ajoutées par ce up(), et rien d'autre. `surface` est une
        //   saisie d'exploitant qui se ressaisit ; `sport` se recalcule trivialement puisque toutes
        //   les lignes d'avant cette migration sont du padel. Aucune autre donnée n'en dépend.
        $this->addSql('ALTER TABLE padel_terrain DROP sport');
        $this->addSql('ALTER TABLE padel_terrain DROP surface');
    }
}
