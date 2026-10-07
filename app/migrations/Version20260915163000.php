<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * SUPPRESSION DE `report_axe_analytique` (arbitrage n°2 du 15/09/2026).
 *
 * @drop-voulu : report_axe_analytique — référentiel mort (six axes posés par les fixtures, lus par
 *   aucun écran ni agrégateur, sans aucune clé étrangère). RIEN ne le remplace ; c'est une
 *   suppression de poids mort tranchée par Maxime le 15/09 (A-REVOIR n°2), pas une dérive.
 *
 * Le référentiel `AxeAnalytique` — six axes posés par les fixtures — n'était consommé par RIEN :
 * aucun écran, aucun moteur d'agrégation, aucune clé étrangère. Vérifié avant de couper : `Indicateur`
 * et `Mesure` ne le référencent pas, et aucune contrainte de la base ne pointe vers
 * `report_axe_analytique`. Un référentiel que personne n'alimente ni ne lit est du poids mort ;
 * Maxime a tranché sa suppression.
 *
 * L'entité, l'enum `TypeAxeAnalytique` et la ressource API partent avec la table dans le même commit,
 * pour que le mapping Doctrine et le schéma restent cohérents.
 *
 * ── LE `down()` RECRÉE L'ÉTAT D'AVANT ──────────────────────────────────────────────────────────
 *
 * ⚠ ORDRE : `addSql()` est différé — les requêtes s'exécutent dans l'ordre où elles sont empilées.
 * Le `CREATE TABLE` est donc empilé AVANT les `INSERT`, sinon les insertions viseraient une table
 * inexistante. Les six axes sont ré-insérés avec de nouveaux `id` : aucune FK ne les référence,
 * l'identité exacte n'a aucune conséquence, et c'est bien la restauration de l'état antérieur — un
 * référentiel mort compris.
 */
final class Version20260915163000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Supprime le référentiel mort report_axe_analytique (arbitrage n°2 du 15/09) — consommé par rien, aucune FK.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('DROP TABLE report_axe_analytique');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TABLE report_axe_analytique (id BINARY(16) NOT NULL, code VARCHAR(30) NOT NULL, libelle VARCHAR(80) NOT NULL, type VARCHAR(20) NOT NULL, granularites JSON DEFAULT NULL, est_extension TINYINT DEFAULT 0 NOT NULL, actif TINYINT DEFAULT 1 NOT NULL, UNIQUE INDEX uniq_axe_code (code), PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4');

        $axes = [
            ['site', 'Site', 'site', null, 0],
            ['activite', 'Activité', 'activite', null, 0],
            ['produit', 'Produit', 'produit', null, 0],
            ['categorie', 'Catégorie', 'categorie', null, 1],
            ['periode', 'Période', 'periode', '["jour","semaine","mois","annee"]', 0],
            ['canal', 'Canal', 'canal', null, 1],
        ];
        foreach ($axes as [$code, $libelle, $type, $granularites, $estExtension]) {
            $this->addSql(
                'INSERT INTO report_axe_analytique (id, code, libelle, type, granularites, est_extension, actif) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [Uuid::v4()->toBinary(), $code, $libelle, $type, $granularites, $estExtension],
            );
        }
    }
}
