<?php

declare(strict_types=1);

namespace App\Membership\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Membership\Entity\Resiliation;
use App\Membership\Service\DemanderResiliationHandler;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/resiliations/{id}/valider-motif-legitime (CA-3, spec §4.3 : validation manuelle
 * obligatoire, pas d'auto-approbation).
 *
 * @implements ProcessorInterface<Resiliation, Resiliation>
 */
final class ValiderMotifLegitimeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DemanderResiliationHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Resiliation
    {
        \assert($data instanceof Resiliation);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        return $this->handler->validerMotifLegitime($data, $utilisateur);
    }
}
