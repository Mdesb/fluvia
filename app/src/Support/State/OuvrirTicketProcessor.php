<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\StatutTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST /support/tickets (US-SUP-09, RG-SUP-09, CA-8) : ouverture par un exploitant authentifié,
 * rattaché à son établissement actif (en-tête `X-Etablissement`) et à lui-même.
 *
 * @implements ProcessorInterface<TicketSupport, TicketSupport>
 */
final class OuvrirTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): TicketSupport
    {
        \assert($data instanceof TicketSupport);
        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $etablissement = $this->contexte->etablissementActif();
        if ($etablissement === null) {
            throw new UnprocessableEntityHttpException("Établissement actif requis (en-tête X-Etablissement) pour ouvrir un ticket.");
        }

        $data->setEtablissement($etablissement);
        $data->setDemandeur($utilisateur);
        $data->setStatut(StatutTicket::Nouveau);

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
