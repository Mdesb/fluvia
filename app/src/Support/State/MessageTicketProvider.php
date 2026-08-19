<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\MessageTicket;
use App\Support\Entity\TicketSupport;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /support/tickets/{ticketId}/messages (RG-SUP-12, CA-11) : filtre les notes internes
 * (`noteInterne=true`) pour un demandeur, jamais pour un agent support/admin.
 *
 * @implements ProviderInterface<list<MessageTicket>>
 */
final class MessageTicketProvider implements ProviderInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $ticketId = $uriVariables['ticketId'] ?? null;
        // API Platform type la variable d'apres l'identifiant de l'entite liee : c'est un `Uuid`,
        // pas une chaine. On accepte les deux plutot que de dependre de la forme exacte produite —
        // un `\is_string()` seul echouait ici en silence, et se lisait comme un ticket inexistant.
        $ticket = (\is_string($ticketId) || $ticketId instanceof \Stringable)
            ? $this->em->getRepository(TicketSupport::class)->find((string) $ticketId)
            : null;
        if (!$ticket instanceof TicketSupport) {
            return [];
        }

        $utilisateur = $this->security->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return [];
        }

        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());
        $estAgent = $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
            || $this->calculateur->autorise($codes, 'support', 'administrer')
            || $this->calculateur->autorise($codes, 'support', 'lire_ticket_etablissement');
        $estDemandeur = $ticket->getDemandeur() !== null && (string) $ticket->getDemandeur()->getId() === (string) $utilisateur->getId();

        if (!$estAgent && !$estDemandeur) {
            return [];
        }

        $qb = $this->em->getRepository(MessageTicket::class)->createQueryBuilder('m')
            ->andWhere('m.ticket = :ticket')
            ->setParameter('ticket', $ticket->getId(), 'uuid')
            ->orderBy('m.dateCreation', 'ASC');

        if (!$estAgent) {
            $qb->andWhere('m.noteInterne = false');
        }

        /** @var list<MessageTicket> */
        return $qb->getQuery()->getResult();
    }
}
