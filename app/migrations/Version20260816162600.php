<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;
use Symfony\Component\Uid\Uuid;

/**
 * Module M7 Reporting (L11) — migration de données : référentiel de base `AxeAnalytique` (§4.3
 * spec, RG-M7-05 : site/activité/produit/période au cahier, catégorie/canal = extension) et
 * `Indicateur` (§1.3/§5.3 plan-reporting.md) — CA, FREQUENTATION_CUMULEE, FMI_MAX,
 * FMI_MAX_SOMME_SITES, FMI_MAX_SITE_CRITIQUE (libellés explicites, RG-M7-04), TAUX_REMPLISSAGE,
 * NO_SHOW, IMPAYES, FOND_CAISSE. Idempotente (INSERT IGNORE, contrainte unique sur `code`).
 */
final class Version20260816162600 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'M7 Reporting (L11) : référentiel de base — 6 AxeAnalytique + 9 Indicateur.';
    }

    public function up(Schema $schema): void
    {
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
                'INSERT IGNORE INTO report_axe_analytique (id, code, libelle, type, granularites, est_extension, actif) VALUES (?, ?, ?, ?, ?, ?, 1)',
                [Uuid::v4()->toBinary(), $code, $libelle, $type, $granularites, $estExtension],
            );
        }

        $indicateurs = [
            ['CA', 'Chiffre d\'affaires encaissé', 'euro', 'somme', 'cumule', 'vente', 60],
            ['FREQUENTATION_CUMULEE', 'Fréquentation cumulée', 'nombre', 'somme', 'cumule', 'acces', 60],
            ['FMI_MAX', 'FMI max (présence simultanée maximale, site)', 'nombre', 'max', 'instantane', 'acces', 60],
            ['FMI_MAX_SOMME_SITES', 'Somme des FMI max des sites', 'nombre', 'somme', 'instantane', 'acces', 60],
            ['FMI_MAX_SITE_CRITIQUE', 'FMI max — site le plus critique', 'nombre', 'max', 'instantane', 'acces', 60],
            ['TAUX_REMPLISSAGE', 'Taux de remplissage', 'pourcentage', 'moyenne', 'cumule', 'reservation', 60],
            ['NO_SHOW', 'No-show', 'nombre', 'somme', 'cumule', 'reservation', 60],
            ['IMPAYES', 'Impayés (montant)', 'euro', 'somme', 'cumule', 'recouvrement', 60],
            ['FOND_CAISSE', 'Fond de caisse théorique', 'euro', 'somme', 'instantane', 'compta', 60],
        ];
        foreach ($indicateurs as [$code, $libelle, $unite, $modeCalcul, $nature, $sourceModule, $seuil]) {
            $this->addSql(
                'INSERT IGNORE INTO report_indicateur (id, code, libelle, unite, mode_calcul, nature, source_module, seuil_completude_minutes, actif) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)',
                [Uuid::v4()->toBinary(), $code, $libelle, $unite, $modeCalcul, $nature, $sourceModule, $seuil],
            );
        }
    }

    public function down(Schema $schema): void
    {
        $this->addSql("DELETE FROM report_indicateur WHERE code IN ('CA','FREQUENTATION_CUMULEE','FMI_MAX','FMI_MAX_SOMME_SITES','FMI_MAX_SITE_CRITIQUE','TAUX_REMPLISSAGE','NO_SHOW','IMPAYES','FOND_CAISSE')");
        $this->addSql("DELETE FROM report_axe_analytique WHERE code IN ('site','activite','produit','categorie','periode','canal')");
    }
}
