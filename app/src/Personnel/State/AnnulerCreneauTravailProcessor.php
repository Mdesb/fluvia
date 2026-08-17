<?php

declare(strict_types=1);

namespace App\Personnel\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Personnel\Entity\CreneauTravail;
use App\Personnel\Enum\StatutCreneauTravail;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annule un CreneauTravail (POST /personnel/creneaux-travail/{id}/annuler).
 *
 * @implements ProcessorInterface<CreneauTravail, CreneauTravail>
 */
final class AnnulerCreneauTravailProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): CreneauTravail
    {
        \assert($data instanceof CreneauTravail);

        $data->setStatut(StatutCreneauTravail::Annule);
        $this->em->flush();

        return $data;
    }
}
