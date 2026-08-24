<?php

declare(strict_types=1);

namespace App\Stay\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stay\Entity\Stay;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Départ du client (ACT-3). Aucun corps.
 *
 * **Clôturer ne règle rien.** Le compte est figé, il peut rester débiteur — facturation différée à une
 * société, litige sur une ligne. C'est `SettleStayProcessor` qui solde, et la séparation des deux
 * endpoints reprend celle des deux dates portées par l'entité.
 *
 * @implements ProcessorInterface<mixed, Stay>
 */
final class CloseStayProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly StayFromRequest $sejours,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Stay
    {
        $sejour = $this->sejours->resolve($uriVariables);

        if (null !== $sejour->getSettledAt()) {
            throw new ConflictHttpException('stay.error.stay_already_settled');
        }

        // `close()` est idempotent par conception : le comptoir cloture parfois deux fois, et lever
        // sur le second appel transformerait une maladresse en incident.
        $sejour->close(new \DateTimeImmutable('now'));
        $this->em->flush();

        return $sejour;
    }
}
