<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * LE RAYONNAGE PHYSIQUE — OÙ L'ARTICLE SE TROUVE, ET NON OÙ IL S'AFFICHE.
 *
 * ── LES DEUX RAYONNAGES DE R3, ET POURQUOI ILS NE SONT PAS LE MÊME CHAMP ────────────────────────
 *
 * Maxime les a distingués lui-même : « il y a une distinction à faire entre un rayonnage physique
 * et un rayonnage sur la caisse qui est purement un système d'affichage ».
 *
 *     rayon de CAISSE     une catégorie d'axe `rayon`, PLUSIEURS par produit, qui range un écran
 *     rayon PHYSIQUE      un lieu, UN seul, qui répond à « où est-ce que je vais le chercher »
 *
 * Les confondre aurait donné un champ qui ment dans les deux sens : un article rangé en réserve
 * mais affiché dans deux rayons de caisse n'aurait plus eu de lieu, et un rayon de caisse aurait
 * prétendu dire où aller.
 *
 * ── ⚠ UN TEXTE LIBRE, ET C'EST UN CHOIX ────────────────────────────────────────────────────────
 *
 * Pas de référentiel de lieux, pas de hiérarchie allée/travée/niveau. Personne n'a demandé ça, et
 * un référentiel qu'il faut alimenter avant de pouvoir écrire « Réserve » se contourne en écrivant
 * n'importe quoi dedans. Un texte se trie, se cherche, et se remplace par un référentiel le jour où
 * quelqu'un en aura vraiment besoin — l'inverse est beaucoup plus cher.
 *
 * ⚠ NOM DE COLONNE EN ANGLAIS (D5) : la colonne est AJOUTÉE, donc elle relève de la décision du
 * 19/08, même si l'entité qui la porte est ancienne et française. Même règle que
 * `deadline_alerted_at`, posée ce matin sur `crm_demande_rgpd`.
 *
 * ⚠ NULLABLE, DONC SÛRE PENDANT LE DÉPLOIEMENT. `deploy-preprod.sh` applique les migrations AVANT
 * de redémarrer FPM : entre les deux, le schéma est neuf et le code est ancien. Une colonne
 * nullable traverse cette fenêtre sans rien casser.
 *
 * ⚠ D66-ter : aucune donnée métier n'est fabriquée. Les 422 articles existants restent à `NULL` —
 * « on ne sait pas où il est », ce qui est la vérité, et non « il est en réserve », qui serait une
 * invention qu'aucun inventaire n'a constatée.
 */
final class Version20260903120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Ajoute stk_article.storage_location : le rayonnage PHYSIQUE, distinct du rayon de caisse.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE stk_article ADD storage_location VARCHAR(80) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // @drop-voulu : la colonne ajoutée par ce up() et rien d'autre. Ce qu'elle porte est une
        //   saisie d'exploitant, pas un fait calculé — mais elle se ressaisit, et aucune autre
        //   donnée n'en dépend.
        $this->addSql('ALTER TABLE stk_article DROP storage_location');
    }
}
