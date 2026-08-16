<?php

declare(strict_types=1);

namespace App\Patinoire\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Patinoire\Entity\ListeAttentePointure;
use App\Patinoire\Enum\StatutListeAttentePointure;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Annulation d'une inscription en liste d'attente pointure (POST /patinoire/liste-attente/{id}/annuler,
 * US-PATIN-05). Marque l'inscription `expiree` (pas de suppression physique, traçabilité).
 *
 * @implements ProcessorInterface<ListeAttentePointure, ListeAttentePointure>
 */
final class AnnulerListeAttenteProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): ListeAttentePointure
    {
        \assert($data instanceof ListeAttentePointure);

        $data->setStatut(StatutListeAttentePointure::Expiree);
        $this->em->flush();

        return $data;
    }
}
