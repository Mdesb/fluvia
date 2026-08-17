<?php

declare(strict_types=1);

namespace App\Stock\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Stock\Entity\Inventaire;
use App\Stock\Service\InventaireRegularisationHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * `POST /stock/inventaires/{id}/cloturer` (RG-STOCK-12) : fige les lignes (append-only).
 *
 * @implements ProcessorInterface<mixed, Inventaire>
 */
final class ClorurerInventaireProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InventaireRegularisationHandler $handler,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Inventaire
    {
        \assert($data instanceof Inventaire);

        $this->handler->cloturer($data);
        $this->em->flush();

        return $data;
    }
}
