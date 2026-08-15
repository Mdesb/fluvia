<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Compta\Entity\ExportComptable;
use App\Compta\Service\GenererExportHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /exports (ExportComptable) — RG-EXPORT-07, CA-10/CA-11 : contrôle pré-export, masquage par
 * profil, génère le contenu si pas d'anomalie.
 *
 * @implements ProcessorInterface<ExportComptable, ExportComptable>
 */
final class GenererExportProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GenererExportHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ExportComptable
    {
        \assert($data instanceof ExportComptable);
        $this->em->persist($data);

        return $this->handler->generer($data);
    }
}
