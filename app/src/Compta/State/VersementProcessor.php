<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\BordereauVersement;
use App\Compta\Entity\RegieRecettes;
use App\Compta\Service\RegieHandler;
use App\Vente\Service\LecteurCorps;

/**
 * POST /compta/regies/{id}/versements (US-L4-02, CA-5). Corps :
 *   { "montant": "123.45", "justificatifs": ["ref1", ...]? }
 *
 * @implements ProcessorInterface<RegieRecettes, BordereauVersement>
 */
final class VersementProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly RegieHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): BordereauVersement
    {
        \assert($data instanceof RegieRecettes);

        $corps = $this->lecteur->corps();
        $montant = (int) round(((float) ($corps['montant'] ?? '0')) * 100);
        $justificatifs = \is_array($corps['justificatifs'] ?? null) ? array_map('strval', $corps['justificatifs']) : null;

        return $this->handler->enregistrerVersement($data, $montant, $justificatifs);
    }
}
