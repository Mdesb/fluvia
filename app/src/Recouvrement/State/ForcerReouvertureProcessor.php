<?php

declare(strict_types=1);

namespace App\Recouvrement\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Recouvrement\Entity\IncidentImpaye;
use App\Recouvrement\Service\ResolutionImpayeHandler;
use App\Securite\Entity\Utilisateur;
use App\Vente\Service\LecteurCorps;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /recouvrement/incidents/{id}/forcer-reouverture — motif requis, traçabilité RG-SOCLE-07.
 * Corps : { "motif": string }.
 *
 * @implements ProcessorInterface<IncidentImpaye, IncidentImpaye>
 */
final class ForcerReouvertureProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly LecteurCorps $lecteur,
        private readonly ResolutionImpayeHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): IncidentImpaye
    {
        \assert($data instanceof IncidentImpaye);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        $corps = $this->lecteur->corps();
        $motif = \is_string($corps['motif'] ?? null) ? $corps['motif'] : '';

        return $this->handler->forcerReouverture($data, $utilisateur, $motif);
    }
}
