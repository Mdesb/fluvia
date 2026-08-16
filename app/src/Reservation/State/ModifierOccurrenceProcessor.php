<?php

declare(strict_types=1);

namespace App\Reservation\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Reservation\Entity\Creneau;
use App\Reservation\Service\ChevauchementCreneauGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Modifie une seule occurrence (horaire ou ressource) d'un Créneau récurrent, sans affecter la série
 * ni les occurrences déjà réservées (RG-M5-07, CA-6). La modification est marquée
 * `occurrenceModifiee=true` si le créneau appartient à une récurrence.
 *
 * @implements ProcessorInterface<Creneau, Creneau>
 */
final class ModifierOccurrenceProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ChevauchementCreneauGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Creneau
    {
        \assert($data instanceof Creneau);

        $ressource = $data->getRessource();
        if ($ressource !== null && $this->guard->enConflit($ressource, $data->getDebut(), $data->getFin(), $data->getId())) {
            throw new ConflictHttpException('Conflit de ressource : la nouvelle fenêtre chevauche un autre créneau (RG-M5-03).');
        }

        if ($data->getRecurrence() !== null) {
            $data->setOccurrenceModifiee(true);
        }

        $this->em->flush();

        return $data;
    }
}
