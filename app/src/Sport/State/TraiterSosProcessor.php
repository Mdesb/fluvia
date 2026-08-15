<?php

declare(strict_types=1);

namespace App\Sport\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Sport\Entity\EvenementSOS;
use App\Sport\Service\DeclencherSosHandler;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /sport/sos/{id}/traiter — clôture tracée d'un événement SOS.
 *
 * @implements ProcessorInterface<EvenementSOS, EvenementSOS>
 */
final class TraiterSosProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly DeclencherSosHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): EvenementSOS
    {
        \assert($data instanceof EvenementSOS);

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            throw new UnprocessableEntityHttpException('Utilisateur non authentifié.');
        }

        return $this->handler->traiter($data, $utilisateur);
    }
}
