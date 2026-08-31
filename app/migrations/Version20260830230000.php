<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * La région géographique du client, déduite de son code postal — demandée par Maxime le 30/08.
 *
 * ── POURQUOI UNE COLONNE PLUTÔT QU'UN CALCUL À LA VOLÉE ────────────────────────────────────────
 *
 * Le calcul à la volée est toujours juste et ne demande aucun entretien — mais il interdit de
 * filtrer, grouper ou agréger en base. « Combien de clients par région » obligerait à charger la
 * table entière en mémoire. C'est précisément ce qui ne tient pas dans la durée, et Maxime a demandé
 * « le plus performant sur le long terme ».
 *
 * La colonne est donc stockée, mais **jamais saisie** : `Client::setAdresse()` la recalcule à chaque
 * écriture d'adresse. Elle ne porte aucun groupe de sérialisation en écriture (D41) — si l'appelant
 * pouvait la poser, elle divergerait du code postal et plus rien ne dirait laquelle croire.
 *
 * ── ⚠ CE N'EST PAS `org_region`, ET LES CONFONDRE COÛTERAIT CHER ──────────────────────────────
 *
 *     org_region                 regroupe les ÉTABLISSEMENTS d'un exploitant pour une direction
 *                                régionale. Arbitraire, propre à chacun : l'un met tout le Grand
 *                                Est, l'autre découpe Nord et Grand Est. C'est de la configuration.
 *
 *     crm_client.region_geo…     donnée géographique sur une PERSONNE, la même pour tout le monde,
 *                                et qui se calcule.
 *
 * L'une ne se déduit pas de l'autre. Un client de Lille peut relever de la direction « Grand Est »
 * d'un exploitant et de la région administrative Hauts-de-France.
 *
 * ── L'INDEX EXISTE POUR LA SEULE RAISON D'ÊTRE DE CETTE COLONNE ───────────────────────────────
 *
 * Elle n'est pas là pour être lue sur une fiche — elle est là pour être GROUPÉE. Sans index, un
 * `GROUP BY` sur une table de clients devient un balayage complet, et la colonne perd l'avantage
 * qui l'a fait choisir.
 *
 * ── ⚠ LES LIGNES EXISTANTES RESTENT NULLES ────────────────────────────────────────────────────
 *
 * Cette migration ne remplit rien : `crm:reprendre-regions-geographiques` s'en charge, et son mode par défaut
 * est un constat. Remplir ici obligerait à réécrire la table de correspondance en SQL — une seconde
 * copie qui divergerait de la première au premier découpage administratif modifié.
 */
final class Version20260830230000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Région géographique du client, déduite du code postal (statistique, jamais saisie).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE crm_client ADD region_geographique VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_client_region_geographique ON crm_client (region_geographique)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_client_region_geographique ON crm_client');
        $this->addSql('ALTER TABLE crm_client DROP region_geographique');
    }
}
