<?php

declare(strict_types=1);

namespace App\Boutique\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Boutique\Entity\PanierEnLigne;
use App\Boutique\Security\PanierProprietaireGuard;
use Doctrine\ORM\EntityManagerInterface;

/**
 * POST /boutique/paniers/{id}/vider (§4.3 spec) : vide le panier avant paiement.
 *
 * @implements ProcessorInterface<mixed, PanierEnLigne>
 */
final class ViderPanierEnLigneProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierProprietaireGuard $guard,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PanierEnLigne
    {
        \assert($data instanceof PanierEnLigne);
        $this->guard->verifier($data);

        foreach ($data->getLignes()->toArray() as $ligne) {
            $data->removeLigne($ligne);
            $this->em->remove($ligne);
        }
        $this->em->flush();

        return $data;
    }
}
