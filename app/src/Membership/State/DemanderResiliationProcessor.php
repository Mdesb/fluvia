<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Membership\Entity\Membership;
use App\Membership\Entity\Resiliation;
use App\Membership\Service\DemanderResiliationHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/abonnements/{id}/resiliations (US-SPORT-03, CA-3). Corps :
 *   { "motif": string, "motifLegitime"?: bool, "justificatifChemin"?: string, "dateDemande"?: "AAAA-MM-JJ" }
 *
 * @implements ProcessorInterface<Membership, Resiliation>
 */
final class DemanderResiliationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly DemanderResiliationHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Resiliation
    {
        \assert($data instanceof Membership);

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';
        if (trim($motif) === '') {
            throw new UnprocessableEntityHttpException('« motif » est requis.');
        }
        $motifLegitime = ($corps['motifLegitime'] ?? false) === true;
        $justificatif = isset($corps['justificatifChemin']) && \is_string($corps['justificatifChemin']) ? $corps['justificatifChemin'] : null;
        $dateDemande = isset($corps['dateDemande']) && \is_string($corps['dateDemande'])
            ? new \DateTimeImmutable($corps['dateDemande'])
            : new \DateTimeImmutable('today');

        return $this->handler->demander($data, $dateDemande, $motif, $motifLegitime, $justificatif);
    }
}
