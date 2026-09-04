<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * D'OÙ VIENT LA RECETTE — §8.12.
 *
 * ⚠ CETTE MIGRATION A D'ABORD PORTÉ LE NUMÉRO 20260904160000, DÉJÀ PRIS PAR UN PAIR.
 * `cfc5bc91` (administration du site vitrine) avait créé ce fichier une heure plus tôt ; ma copie
 * l'a ÉCRASÉ en silence — `cp` dans `app/migrations/` ne prévient pas, et `git add -A` a mis
 * l'écrasement en scène comme une simple modification, sans conflit.
 *
 * Deux dégâts, tous deux réparés dans le commit qui porte ce fichier :
 *   · la migration du pair, déjà exécutée, avait perdu son TEXTE — une base neuve n'aurait jamais
 *     créé `website_blog_post` ni `website_blog_category` ;
 *   · la mienne n'aurait JAMAIS tourné : Doctrine avait enregistré la version comme exécutée, et
 *     `doctrine:migrations:status` annonçait « Already at latest version » pendant que les colonnes
 *     n'existaient pas.
 *
 * ⚠ C'est la VÉRIFICATION EN BASE qui l'a montré — pas le déploiement, qui s'était déclaré réussi,
 * ni Doctrine, qui affirmait « migrated ». Un `SELECT` sur la colonne attendue, toujours.
 *
 * ── CE QUE LA MESURE A MONTRÉ ───────────────────────────────────────────────────────────────────
 *
 * La ventilation comptable s'appuie sur la **catégorie** du produit, pas sur le produit
 * (`ProjectionVenteDoctrineAdapter:117`). Multiplier les produits — un par sport, un par durée —
 * ne changeait donc **rien** à la comptabilité : cela ne changeait que ce qu'on pourrait lire
 * ensuite. Et `vente_ligne` ne portait ni activité ni ressource : « combien le padel a-t-il
 * rapporté » était une question sans réponse possible, pour toujours, sur les ventes déjà émises.
 *
 * Arbitrage de Maxime : faire porter l'origine à la ligne, plutôt que de la reporter sur la
 * nomenclature produit.
 *
 * ── ⚠ DEUX COLONNES, ET LA SECONDE EST CELLE QUI SAUVE LE PADEL ─────────────────────────────────
 *
 * Maxime a dit « l'activité ». Mesure faite juste après : `ReserverTerrainProcessor` pose une
 * RESSOURCE et jamais d'activité. En préproduction : **5 créneaux, 4 sans activité, 5 avec
 * ressource**. L'activité seule laisserait 80 % des créneaux — et tout le padel — sans réponse.
 *
 *     activite    quelle PRESTATION a été vendue
 *     ressource   quel ÉQUIPEMENT a été occupé — et c'est elle qui mène au sport, via
 *                 `padel_terrain.sport`, posé le matin même
 *
 * ── ⚠ DES RÉFÉRENCES LIBRES (D58) ───────────────────────────────────────────────────────────────
 *
 * Comme `produit`, `type_tarif` et `saison` sur la même table : un identifiant nu, sans clé
 * étrangère. Elles ne se comparent NI par `IN` en DQL, NI par `SearchFilter` — les deux rendent une
 * liste vide, ce qui ressemble exactement à « il n'y a rien ». Le garde-fou des références libres
 * les comptera, et sa ligne de base est regelée délibérément dans le même commit.
 *
 * ⚠ D66-ter : aucune donnée métier fabriquée. Les 23 lignes existantes restent à `NULL` — on ne sait
 * pas d'où elles viennent, et c'est la vérité. Les reconstituer par jointure serait une invention.
 *
 * ⚠ Nullable, donc sûre pendant le déploiement : les migrations passent avant le redémarrage de FPM.
 */
final class Version20260904170000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'vente_ligne porte son activité et sa ressource : d\'où vient la recette (§8.12).';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE vente_ligne ADD activite BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
        $this->addSql('ALTER TABLE vente_ligne ADD ressource BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\'');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : les deux colonnes ajoutées par ce up(), et rien d'autre. Ce qu'elles portent
        //   est une trace d'origine, reconstituable pour les ventes futures mais perdue pour celles
        //   déjà émises — comme avant cette migration. Aucune autre donnée n'en dépend.
        $this->addSql('ALTER TABLE vente_ligne DROP activite');
        $this->addSql('ALTER TABLE vente_ligne DROP ressource');
    }
}
