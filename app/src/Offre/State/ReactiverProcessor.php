<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\TransitionProduitHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Réactive un produit archivé (archivé → brouillon, US-L1-09) pour le remettre en chantier.
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class ReactiverProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TransitionProduitHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $this->handler->reactiver($data);
        $data->toucherModifieLe();
        $this->em->flush();

        return $data;
    }
}
