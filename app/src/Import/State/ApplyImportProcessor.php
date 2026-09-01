<?php

declare(strict_types=1);

namespace App\Import\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Import\Entity\ImportBatch;
use App\Import\Service\ImportApplier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `POST /imports/{id}/apply` — applique un lot validé, en une transaction.
 *
 * Rien dans le corps : tout ce qu'il faut est déjà dans le lot. Un import qui redemanderait le
 * fichier à l'application pourrait appliquer autre chose que ce qui a été jugé — c'est précisément
 * ce que la conservation du fichier source dans le lot empêche.
 *
 * @implements ProcessorInterface<mixed, mixed>
 */
final class ApplyImportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ImportApplier $applier,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ImportBatch
    {
        if (!$data instanceof ImportBatch) {
            // 404 et non 403 : confirmer l'existence d'un lot d'un autre établissement serait déjà
            // une fuite — il porte le fichier client de quelqu'un d'autre.
            throw new NotFoundHttpException();
        }

        $this->applier->apply($data);
        $this->em->flush();

        return $data;
    }
}
