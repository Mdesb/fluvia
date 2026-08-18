<?php

declare(strict_types=1);

namespace App\Facturation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Facturation\Entity\Facture;
use App\Facturation\Service\EmettreFactureDirecteHandler;

/**
 * POST /factures/{id}/emettre (US-FACT-02, CA-3/CA-4, `plan-facturation.md` §2). Aucun corps requis.
 *
 * @implements ProcessorInterface<Facture, Facture>
 */
final class EmettreFactureDirecteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EmettreFactureDirecteHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Facture
    {
        \assert($data instanceof Facture);

        return $this->handler->emettre($data);
    }
}
