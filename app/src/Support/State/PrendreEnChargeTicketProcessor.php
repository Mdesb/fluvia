<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\NiveauAffectation;
use App\Support\Enum\StatutTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * POST /support/tickets/{id}/prendre-en-charge (CA-9, RG-SUP-11/13) : statut → `en_cours`,
 * `affecteA` = agent courant, `niveauAffectation` = `N1` par défaut (conservé si déjà `N2`).
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class PrendreEnChargeTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $data->setAffecteA($utilisateur);
        if ($data->getNiveauAffectation() === null) {
            $data->setNiveauAffectation(NiveauAffectation::N1);
        }
        $data->setStatut(StatutTicket::EnCours);

        $this->em->flush();

        return $data;
    }
}
