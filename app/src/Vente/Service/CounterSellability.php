<?php

declare(strict_types=1);

namespace App\Vente\Service;

use App\Offre\Entity\Produit;
use App\Offre\Enum\Canal;
use App\Offre\Enum\StatutProduit;
use App\Offre\Service\ProductSaleScopeGuard;
use App\Organisation\Entity\Etablissement;

/**
 * « La caisse de CE site peut-elle vendre CE produit ? » — sur ce site, publié, au guichet.
 *
 * ── LE DÉFAUT QUE CE SERVICE FERME ──────────────────────────────────────────────────────────────
 *
 * Mesuré le 06/10/2026 : `AjoutLigneHandler` résolvait le produit par un `find()` brut et ne
 * regardait ni son statut, ni ses sites, ni ses canaux. Un brouillon, un archivé, un produit du seul
 * site B se vendaient tous sur la caisse du site A (201, ligne écrite). Le défaut datait de la
 * création du module (M2) ; les fixtures de caisse étant en brouillon, la suite le validait.
 *
 * ── DEUX QUESTIONS, PAS UNE ─────────────────────────────────────────────────────────────────────
 *
 * - `siteRefusal()` : le produit appartient-il au catalogue de ce site ? C'est une frontière de
 *   CLOISONNEMENT. Elle est demandée à `ProductSaleScopeGuard`, qui porte la convention socle de
 *   D92 (aucun site = socle, vendu partout) — la règle n'est pas réécrite ici.
 * - `offerRefusal()` : le produit est-il en vente au guichet AUJOURD'HUI (publié, canal guichet) ?
 *   C'est un état COMMERCIAL, qui change dans le temps.
 *
 * La caisse en ligne refuse les deux. La synchro hors ligne ne les traite pas pareil : une vente
 * déjà encaissée sur un produit archivé depuis est une vente réelle, qu'on enregistre et qu'on trace ;
 * un produit d'un autre site n'a jamais pu figurer dans le catalogue local de cette caisse, et
 * l'accepter ferait écrire, sur un ticket de ce site, le libellé et le prix d'un autre catalogue.
 * Voir `AjoutLigneHandler::replayOfflineLine()`.
 *
 * Le site est contrôlé EN PREMIER : pour un produit d'un autre site, on ne dit rien de plus que
 * « pas vendu ici » — ni son statut, ni ses canaux, qui décrivent le catalogue d'un autre.
 */
final readonly class CounterSellability
{
    public function __construct(
        private ProductSaleScopeGuard $scopeGuard,
    ) {
    }

    /** Le motif du refus si ce produit n'est pas au catalogue de ce site ; `null` sinon. */
    public function siteRefusal(Produit $produit, ?Etablissement $site): ?string
    {
        // Une vente sans site ne peut vendre que le socle : fermeture par défaut.
        $soldHere = $site !== null
            ? $this->scopeGuard->isSoldAt($produit, $site)
            : $produit->getEtablissements()->isEmpty();

        return $soldHere ? null : 'Ce produit n\'est pas vendu sur ce site.';
    }

    /** Le motif du refus si ce produit n'est pas en vente au guichet ; `null` sinon. */
    public function offerRefusal(Produit $produit): ?string
    {
        // `match` sans `default` : un statut ajouté demain devra dire ici s'il se vend.
        $motifStatut = match ($produit->getStatut()) {
            StatutProduit::Publie => null,
            StatutProduit::Brouillon => 'Ce produit n\'est pas encore en vente : il est en brouillon. Publiez-le dans le catalogue pour pouvoir le vendre.',
            StatutProduit::Archive => 'Ce produit n\'est plus en vente : il a été archivé.',
        };
        if ($motifStatut !== null) {
            return $motifStatut;
        }

        if (!$produit->aCanal(Canal::Guichet)) {
            return 'Ce produit n\'est pas vendu au guichet. Ajoutez le canal « guichet » dans sa fiche pour le vendre en caisse.';
        }

        return null;
    }
}
