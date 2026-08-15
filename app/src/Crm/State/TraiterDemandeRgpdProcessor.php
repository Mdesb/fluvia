<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\DemandeRGPD;
use App\Crm\Service\EffacementRgpdHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /demandes-rgpd/{id}/traiter (RG-M4-09, CA-17) : anonymise la fiche client, verrouille la
 * demande (`realisee`).
 *
 * @implements ProcessorInterface<DemandeRGPD, JsonResponse>
 */
final class TraiterDemandeRgpdProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly EffacementRgpdHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        \assert($data instanceof DemandeRGPD);
        $administrateur = $this->security->getUser();
        \assert($administrateur instanceof Utilisateur);

        $client = $this->handler->traiter($data, $administrateur);
        $this->em->flush();

        return new JsonResponse([
            'demande' => (string) $data->getId(),
            'statut' => $data->getStatut()->value,
            'client' => (string) $client->getId(),
            'clientStatut' => $client->getStatut()->value,
        ]);
    }
}
