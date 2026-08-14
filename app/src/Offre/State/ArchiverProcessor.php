<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\TransitionProduitHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Archive un produit (CA-11) : action irréversible côté cycle de vie (le produit n'est plus
 * vendable mais reste consultable/historisé). La confirmation UI relève du client (CA-2).
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class ArchiverProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TransitionProduitHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $this->handler->archiver($data);
        $data->toucherModifieLe();
        $this->em->flush();

        return $data;
    }
}
