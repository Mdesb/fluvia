<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Membership\Entity\Membership;
use App\Membership\Entity\PauseAbonnement;
use App\Membership\Service\DemanderPauseHandler;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/abonnements/{id}/pauses (US-SPORT-02, CA-2). Corps :
 *   { "dateDebut": "AAAA-MM-JJ", "dateFin": "AAAA-MM-JJ", "motif"?: string }
 *
 * @implements ProcessorInterface<Membership, PauseAbonnement>
 */
final class DemanderPauseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly DemanderPauseHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PauseAbonnement
    {
        \assert($data instanceof Membership);

        $corps = $this->lecteur->corps();
        $debut = isset($corps['dateDebut']) && \is_string($corps['dateDebut']) ? new \DateTimeImmutable($corps['dateDebut']) : null;
        $fin = isset($corps['dateFin']) && \is_string($corps['dateFin']) ? new \DateTimeImmutable($corps['dateFin']) : null;
        if ($debut === null || $fin === null || $fin < $debut) {
            throw new UnprocessableEntityHttpException('« dateDebut » et « dateFin » sont requises, avec dateFin ≥ dateDebut.');
        }

        $motif = isset($corps['motif']) && \is_string($corps['motif']) ? $corps['motif'] : null;

        return $this->handler->demander($data, $debut, $fin, $motif);
    }
}
