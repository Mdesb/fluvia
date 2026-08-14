<?php

declare(strict_types=1);

namespace App\Vente\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Vente\Entity\Vente;
use App\Vente\Service\PanierCalculateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * Vide le panier (POST /ventes/{id}/vider, CA-3). La confirmation utilisateur relève de l'UI ; côté
 * API l'action supprime toutes les lignes non validées. Interdit si la vente est validée (NF525).
 *
 * @implements ProcessorInterface<Vente, Vente>
 */
final class ViderPanierProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly PanierCalculateur $calc,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Vente
    {
        \assert($data instanceof Vente);
        if ($data->estScellee()) {
            throw new ConflictHttpException('Vente validée : panier figé (NF525).');
        }

        foreach ($data->getLignes()->toArray() as $ligne) {
            $data->removeLigne($ligne);
            $this->em->remove($ligne);
        }
        $this->calc->recalculerVente($data);
        $this->em->flush();

        return $data;
    }
}
