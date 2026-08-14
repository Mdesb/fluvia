<?php

declare(strict_types=1);

namespace App\Offre\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Offre\Entity\Produit;
use App\Offre\Service\TransitionProduitHandler;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Publie un produit (RG-M1-09 / CA-11). En cas de prérequis manquants, le handler lève une 422
 * listant précisément ce qui manque.
 *
 * @implements ProcessorInterface<Produit, Produit>
 */
final class PublierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly TransitionProduitHandler $handler,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        \assert($data instanceof Produit);

        $this->handler->publier($data);
        $data->toucherModifieLe();
        $this->em->flush();

        return $data;
    }
}
