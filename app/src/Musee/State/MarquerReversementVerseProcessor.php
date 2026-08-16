<?php

declare(strict_types=1);

namespace App\Musee\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Musee\Entity\Reversement;
use App\Musee\Enum\StatutReversement;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /musee/reversements/{id}/marquer-verse (RG-MUS-04).
 *
 * @implements ProcessorInterface<Reversement, Reversement>
 */
final class MarquerReversementVerseProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Reversement
    {
        \assert($data instanceof Reversement);
        $data->setStatut(StatutReversement::Verse);
        $this->em->flush();

        return $data;
    }
}
