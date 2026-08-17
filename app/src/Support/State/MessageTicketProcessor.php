<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\MessageTicket;
use App\Support\Entity\TicketSupport;
use App\Support\Enum\AuteurTypeMessage;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * POST /support/tickets/{ticketId}/messages (US-SUP-11, RG-SUP-12, CA-11) : un demandeur ne peut
 * répondre que sur **son propre** ticket, jamais poser `noteInterne=true` (forcé `false` quel que
 * soit le corps soumis) ; un agent support (N1/N2/admin) peut répondre sur tout ticket de son
 * périmètre et poser une note interne.
 *
 * @implements ProcessorInterface<MessageTicket, MessageTicket>
 */
final class MessageTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): MessageTicket
    {
        \assert($data instanceof MessageTicket);

        $ticketId = $uriVariables['ticketId'] ?? null;
        $ticket = \is_string($ticketId) ? $this->em->getRepository(TicketSupport::class)->find($ticketId) : null;
        if (!$ticket instanceof TicketSupport) {
            throw new NotFoundHttpException('Ticket introuvable.');
        }

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());
        $estAgent = $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
            || $this->calculateur->autorise($codes, 'support', 'administrer');
        $estDemandeur = $ticket->getDemandeur() !== null && (string) $ticket->getDemandeur()->getId() === (string) $utilisateur->getId();

        if (!$estAgent && !$estDemandeur) {
            throw new AccessDeniedHttpException('Vous ne pouvez répondre que sur votre propre ticket.');
        }

        $data->setTicket($ticket);
        $data->setAuteur($utilisateur);

        if ($estAgent) {
            $data->setAuteurType(AuteurTypeMessage::Agent);
        } else {
            $data->setAuteurType(AuteurTypeMessage::Demandeur);
            $data->setNoteInterne(false);
        }

        $ticket->toucherDateMaj();

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
