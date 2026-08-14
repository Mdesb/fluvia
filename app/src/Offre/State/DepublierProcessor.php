<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\TransitionProduitHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Dépublie un produit (publié → brouillon, décision US-L1-09) : autorisé uniquement si aucune
 * vente/dépendance active (sinon 409 orientant vers « archiver »), via le port
 * DependanceVenteInterface (stub L1). Journalisé par l'audit onFlush du socle.
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class DepublierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TransitionProduitHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $this->handler->depublier($data);
        $data->toucherModifieLe();
        $this->em->flush();

        return $data;
    }
}
