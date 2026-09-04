<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * UN TYPE DE PRODUIT POUR LES PRESTATIONS RÉSERVÉES — visite guidée, cours, créneau de terrain.
 *
 * ── L'ARBITRAGE ─────────────────────────────────────────────────────────────────────────────────
 *
 * `ReserverProcessor:206` refuse une réservation payante dont l'activité n'a pas de
 * `produitTarifReference` : sans lui, la vente ne se rattache à aucune comptabilité (le handler en
 * inventait un, `Uuid::v4()`, avant le correctif du 04/09). Les trois activités de la préproduction
 * n'en ont aucun, donc aucune réservation payante ne passe.
 *
 * Restait à décider SOUS QUEL TYPE elles vendent. Maxime a tranché le 04/09, sur mesure et en choix
 * multiple : **un type neuf, « prestation »**.
 *
 * ⚠ POURQUOI PAS UN TYPE EXISTANT — LE POINT QUI DÉCIDE : `ValiderVenteService::emetSupport()` fait
 * ÉMETTRE UN SUPPORT (billet, carte) à tout produit dont le type porte `billet`, `carnet` ou
 * `acces`. Les trois types vendables existants les portent tous. Et la facturation d'un NO-SHOW
 * passe par la même validation (`DebitPmvStrategie` appelle `ValiderVenteService`) : facturer une
 * absence AURAIT IMPRIMÉ UN BILLET À QUELQU'UN QUI N'EST JAMAIS VENU.
 *
 * Le seul type existant qui n'émet rien est `boutique_stock` — mais il s'appelle « Boutique
 * (marchandise) » et porte la facette `stock`. Une visite guidée serait apparue comme une
 * marchandise dans les écrans de boutique : le modèle aurait menti pour éviter un billet.
 *
 * ── ⚠ CE QUE LA FACETTE `consommateur` FAIT : RIEN ──────────────────────────────────────────────
 *
 * Mesuré le 04/09 sur `app/src`, `app/tests` et `frontend/src` : `consommateur` n'est LU par aucun
 * code. Toutes ses occurrences sont des déclarations (fixtures, générateur piscine, la liste que
 * `MappingConversion` diffe) ou le nom commun français dans des commentaires. Témoin positif de la
 * même recherche : `FACETTE_BILLET`, lui, est bien trouvé chez son lecteur `ValiderVenteService`.
 *
 * Elle est posée pour la seule cohérence avec ses trois sœurs vendables — `entree_unitaire`,
 * `carte`, `boutique_stock` la portent, `abonnement` non. ⚠ QUE PERSONNE N'EN DÉDUISE UN
 * COMPORTEMENT : si un jour un mécanisme lit `consommateur`, il s'appliquera d'un coup aux
 * prestations sans que rien ne le signale ici.
 *
 * ── ⚠ CE QUE CETTE MIGRATION NE FAIT PAS ────────────────────────────────────────────────────────
 *
 * Elle pose le TYPE, pas les produits. Aucune activité ne se met à vendre par son effet : il faut
 * ensuite créer un produit de ce type et le rattacher à chaque activité, depuis l'écran des
 * activités. C'est délibéré — le libellé et le PRIX d'une prestation sont des décisions
 * commerciales, et une migration qui les inventerait poserait un tarif que personne n'a validé.
 *
 * Le compte qui dit que l'étape suivante est finie :
 *
 *     SELECT COUNT(*) FROM reservation_activite WHERE produit_tarif_reference_id IS NULL;  -- doit rendre 0
 *
 * ⚠ Insertion gardée par un `NOT EXISTS` sur le code : `uniq_type_produit_code` ferait échouer une
 * seconde pose, et un environnement où un pair aurait déjà créé ce code ne doit pas voir sa
 * migration mourir.
 */
final class Version20260904232000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Type de produit « prestation » (facette consommateur seule) : vendre une réservation sans émettre de billet.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            INSERT INTO off_type_produit (id, code, libelle, facettes, defauts)
            SELECT UNHEX(REPLACE(UUID(), '-', '')), 'prestation', 'Prestation réservée', '["consommateur"]', NULL
            FROM DUAL
            WHERE NOT EXISTS (SELECT 1 FROM off_type_produit t WHERE t.code = 'prestation')
        SQL);
    }

    public function down(Schema $schema): void
    {
        // ⚠ ON NE RETIRE PAS UN TYPE QUI SERT. Cinq clés étrangères pointent vers `off_type_produit`
        // (produit, conversion × 2, compatibilité × 2) : supprimer une ligne référencée ferait
        // échouer la migration inverse au milieu, ou pire, effacerait le type d'un produit vendu.
        // Le `NOT EXISTS` laisse donc la ligne en place si quoi que ce soit s'y rattache — un
        // retour en arrière silencieusement partiel vaut mieux qu'une base cassée.
        $this->addSql(<<<'SQL'
            DELETE FROM off_type_produit
            WHERE code = 'prestation'
              AND NOT EXISTS (SELECT 1 FROM off_produit p WHERE p.type_id = off_type_produit.id)
              AND NOT EXISTS (SELECT 1 FROM off_conversion_type c
                              WHERE c.ancien_type_id = off_type_produit.id OR c.nouveau_type_id = off_type_produit.id)
              AND NOT EXISTS (SELECT 1 FROM off_type_produit_compatible k
                              WHERE k.source_id = off_type_produit.id OR k.cible_id = off_type_produit.id)
        SQL);
    }
}
