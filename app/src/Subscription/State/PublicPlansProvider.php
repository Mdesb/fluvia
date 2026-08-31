<?php

declare(strict_types=1);

namespace App\Subscription\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Subscription\ApiResource\PublicPlan;
use App\Subscription\Exception\InvalidOfferException;
use App\Subscription\Service\OfferCatalog;

/**
 * Les formules affichables sur le site vitrine (ED-5).
 *
 * **Une formule incohérente n'est pas annoncée.** `assertPlanIsCoherent()` refuse une formule qui
 * promet une capacité que le catalogue technique ne connaît pas : elle se vendrait normalement et ne
 * se livrerait jamais. La laisser sur la vitrine mettrait le prospect en échec au moment où il
 * compose son panier — après avoir choisi, donc au pire moment. On l'écarte de l'affichage public ;
 * elle reste visible côté éditeur, qui est l'endroit où l'erreur doit se corriger.
 *
 * **Chaque capacité part avec son libellé.** Le catalogue technique en porte un ; s'en passer
 * obligerait la page à traduire des codes elle-même, et le jour où un code change c'est la vitrine
 * qui afficherait un mot faux, sans que rien ne le signale.
 *
 * @implements ProviderInterface<PublicPlan>
 */
final class PublicPlansProvider implements ProviderInterface
{
    public function __construct(
        private readonly OfferCatalog $catalog,
        private readonly CatalogueCapacites $capacites,
    ) {
    }

    /** @return list<PublicPlan> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $affichables = [];

        foreach ($this->catalog->activePlans() as $plan) {
            try {
                $this->catalog->assertPlanIsCoherent($plan);
            } catch (InvalidOfferException) {
                continue;
            }

            $item = new PublicPlan();
            $item->code = $plan->getCode();
            $item->label = $plan->getLabel();
            $item->monthlyPriceCents = $plan->getMonthlyPriceCents();
            $item->includedCapabilities = $this->decrire($plan->getIncludedCapabilities());

            $affichables[] = $item;
        }

        return $affichables;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<array{capability: string, label: string}>
     */
    private function decrire(array $codes): array
    {
        $decrites = [];

        foreach ($codes as $code) {
            // La cohérence a déjà été vérifiée plus haut : un descripteur manquant ici serait une
            // incohérence entre deux lectures du même catalogue. On retombe sur le code plutôt que de
            // faire échouer une page publique — mais on ne l'invente pas.
            $decrites[] = [
                'capability' => $code,
                'label' => $this->capacites->trouve($code)?->libelle ?? $code,
            ];
        }

        return $decrites;
    }
}
