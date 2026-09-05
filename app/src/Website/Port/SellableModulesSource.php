<?php

declare(strict_types=1);

namespace App\Website\Port;

/**
 * Ce que l'éditeur vend RÉELLEMENT, vu depuis le site public.
 *
 * **Le défaut que ce port supprime.** La page d'accueil listait ses modules depuis
 * {@see \App\Website\Service\ModuleCatalog}, c'est-à-dire depuis le catalogue des CAPACITÉS du
 * produit. Or une capacité qui existe n'est pas une capacité en vente : trois d'entre elles —
 * hébergement, restauration, séjours — n'ont aucune option active. Le site annonçait donc « 20
 * modules » et en vantait trois qu'un visiteur ne pouvait pas acheter, pendant que la section
 * Tarifs, elle, n'en proposait que dix-sept. **La même page se contredisait.**
 *
 * Arbitrage de Maxime le 05/09 : « on ne montre que ce qui est vendable », avec un lien automatique
 * sur son catalogue éditeur. Ce port est ce lien.
 *
 * ---
 *
 * **POURQUOI UN PORT PLUTÔT QU'UN APPEL DIRECT.** Le site public n'a pas à connaître
 * `App\Subscription`, ses entités ni son ORM : il a besoin d'une réponse à une question — « que
 * vend-on ? » — et rien de plus. Le module qui détient la réponse fournit l'adaptateur. C'est la
 * forme déjà retenue pour {@see \App\Sepa\Port\EcheanceSepaSource}.
 *
 * **ET LA SOURCE DE VÉRITÉ RESTE UNIQUE.** L'implémentation lit
 * {@see \App\Subscription\Service\OfferCatalog::activeOptions()} — le point de passage unique de la
 * vente ET de la composition d'offre. Le site affichera donc toujours exactement ce que le tunnel
 * accepte de vendre, sans qu'aucune liste n'ait à être tenue à jour à la main.
 */
interface SellableModulesSource
{
    /**
     * Les codes de capacité actuellement en vente.
     *
     * ⚠ **UN TABLEAU VIDE EST UNE RÉPONSE LÉGITIME**, pas une panne : un catalogue en cours de
     * préparation ne vend rien. L'appelant doit le traiter comme tel — masquer la section plutôt
     * que d'afficher une grille vide, et surtout jamais retomber sur « tout montrer », ce qui
     * ramènerait le défaut que ce port existe pour supprimer.
     *
     * @return list<string>
     */
    public function sellableCapabilities(): array;
}
