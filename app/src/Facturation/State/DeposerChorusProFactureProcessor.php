<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\FactureB2G;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\DepotChorusProHandler;
use App\Vente\Service\LecteurCorps;

/**
 * POST /factures/{id}/chorus (US-FACT-06, RG-FACT-07, CA-9). Corps :
 *   { "numeroEngagement"?: "…", "serviceExecutant"?: "…" }
 * Rejouable sans nouveau numéro de facture en cas d'échec de dépôt (§7 cas limite de la spec).
 *
 * @implements ProcessorInterface<Facture, FactureB2G>
 */
final class DeposerChorusProFactureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly DepotChorusProHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): FactureB2G
    {
        \assert($data instanceof Facture);

        $corps = $this->lecteur->corps();
        $numeroEngagement = \is_string($corps['numeroEngagement'] ?? null) ? $corps['numeroEngagement'] : null;
        $serviceExecutant = \is_string($corps['serviceExecutant'] ?? null) ? $corps['serviceExecutant'] : null;

        return $this->handler->deposer($data, $numeroEngagement, $serviceExecutant);
    }
}
