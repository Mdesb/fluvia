<?php

declare(strict_types=1);

namespace App\Subscription\Service;

use App\Fonctionnalite\Service\CatalogueCapacites;
use App\Subscription\Entity\Plan;
use App\Subscription\Entity\PlanOption;
use App\Subscription\Exception\InvalidOfferException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Le catalogue commercial : quelles formules existent, quelles options sont vendables, et ce que
 * coûte une composition donnée (ED-1).
 *
 * **Une seule source de vérité.** Une option facturable est une capacité du catalogue technique
 * (RG-ED-03) : ce service refuse de composer une offre autour d'un code que `CatalogueCapacites` ne
 * connaît pas. On ne peut donc pas vendre une capacité qui n'existe pas — ni, symétriquement,
 * facturer quelque chose que `ModuleAccess::hasModule()` serait incapable d'activer ensuite.
 *
 * **Échec fermé, y compris commercialement.** Une capacité demandée qui n'est ni comprise dans la
 * formule ni vendable en option fait échouer la composition. L'alternative — l'ignorer en silence —
 * produirait un client convaincu d'avoir acheté un module qu'il n'aura jamais, ce qui se découvre
 * après le paiement.
 */
final class OfferCatalog
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly CatalogueCapacites $capacites,
    ) {
    }

    /** @return list<Plan> formules en vente, triées par prix croissant */
    public function activePlans(): array
    {
        /** @var list<Plan> $plans */
        $plans = $this->em->getRepository(Plan::class)->findBy(['active' => true], ['monthlyPriceCents' => 'ASC']);

        return $plans;
    }

    public function planByCode(string $code): ?Plan
    {
        return $this->em->getRepository(Plan::class)->findOneBy(['code' => $code]);
    }

    /** @return array<string, PlanOption> options en vente, indexées par capacité */
    public function activeOptions(): array
    {
        /** @var list<PlanOption> $options */
        $options = $this->em->getRepository(PlanOption::class)->findBy(['active' => true]);

        $parCapacite = [];
        foreach ($options as $option) {
            $parCapacite[$option->getCapability()] = $option;
        }

        return $parCapacite;
    }

    /**
     * Les capacités demandées qui seront facturées **en supplément** de la formule.
     *
     * Une capacité déjà comprise dans la formule n'apparaît pas : on ne la facture pas deux fois parce
     * que le prospect l'a cochée.
     *
     * @param list<string> $capacitesVoulues
     *
     * @return list<string> triées, dédoublonnées
     *
     * @throws InvalidOfferException si une capacité est inconnue du catalogue ou non vendable
     */
    public function billableExtras(Plan $plan, array $capacitesVoulues): array
    {
        $options = $this->activeOptions();
        $extras = [];

        foreach (array_unique($capacitesVoulues) as $capacite) {
            if (!$this->capacites->existe($capacite)) {
                throw new InvalidOfferException(sprintf(
                    'Capacité inconnue du catalogue : « %s ». Une offre ne peut porter que des capacités '
                    .'réelles — sans quoi on vend un module que personne ne saura activer.',
                    $capacite,
                ));
            }

            if ($plan->includes($capacite)) {
                continue;
            }

            if (!isset($options[$capacite])) {
                throw new InvalidOfferException(sprintf(
                    'La capacité « %s » n\'est pas comprise dans la formule « %s » et n\'est pas vendable '
                    .'en option. Ajoute-la au catalogue d\'options avant de la proposer.',
                    $capacite,
                    $plan->getCode(),
                ));
            }

            $extras[] = $capacite;
        }

        sort($extras);

        return $extras;
    }

    /**
     * Prix mensuel total, en centimes : la formule plus ses suppléments.
     *
     * @param list<string> $capacitesVoulues
     *
     * @throws InvalidOfferException
     */
    public function monthlyPriceCents(Plan $plan, array $capacitesVoulues): int
    {
        $options = $this->activeOptions();
        $total = $plan->getMonthlyPriceCents();

        foreach ($this->billableExtras($plan, $capacitesVoulues) as $capacite) {
            $total += $options[$capacite]->getMonthlyPriceCents();
        }

        return $total;
    }

    /**
     * Vérifie qu'une formule ne promet que des capacités réelles.
     *
     * À appeler avant d'enregistrer une formule : une formule qui inclut une capacité fantôme se vend
     * normalement et ne se livre jamais.
     *
     * @throws InvalidOfferException
     */
    public function assertPlanIsCoherent(Plan $plan): void
    {
        foreach ($plan->getIncludedCapabilities() as $capacite) {
            if (!$this->capacites->existe($capacite)) {
                throw new InvalidOfferException(sprintf(
                    'La formule « %s » inclut la capacité « %s », inconnue du catalogue.',
                    $plan->getCode(),
                    $capacite,
                ));
            }
        }
    }
}
