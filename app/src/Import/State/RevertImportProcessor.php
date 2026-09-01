<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Service\ImportReverter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /imports/{id}/revert` — défait exactement ce que ce lot a créé.
 *
 * Refuse dès qu'une ligne a servi depuis : on ne défait pas ce qui a déjà servi, on corrige par un
 * second import.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class RevertImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportReverter $reverter,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        if (!$data instanceof ImportBatch) {
            throw new NotFoundHttpException();
        }

        $this->reverter->revert($data);
        $this->em->flush();

        return $data;
    }
}
