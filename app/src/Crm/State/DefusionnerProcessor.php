<?php

declare(strict_types=1);

namespace App\Crm\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Crm\Entity\JournalFusion;
use App\Crm\Service\FusionHandler;
use App\Securite\Entity\Utilisateur;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /crm/fusions/{id}/defusionner (RG-M4-06, CA-14) : restaure les fiches sources à l'identique.
 *
 * @implements ProcessorInterface<JournalFusion, JournalFusion>
 */
final class DefusionnerProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly FusionHandler $handler,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JournalFusion
    {
        \assert($data instanceof JournalFusion);
        $administrateur = $this->security->getUser();
        \assert($administrateur instanceof Utilisateur);

        $this->handler->defusionner($data, $administrateur);
        $this->em->flush();

        return $data;
    }
}
