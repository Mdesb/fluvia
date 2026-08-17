<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Service\CalculateurDroits;
use App\Securite\Service\ContexteEtablissement;
use App\Support\Entity\PieceJointeTicket;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * POST pièce jointe de message de ticket (§4.12 spec) : le message parent doit exister et
 * appartenir à un ticket visible de l'utilisateur courant (demandeur du ticket ou agent support).
 *
 * @implements ProcessorInterface<PieceJointeTicket, PieceJointeTicket>
 */
final class PieceJointeTicketProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Security $security,
        private readonly CalculateurDroits $calculateur,
        private readonly ContexteEtablissement $contexte,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): PieceJointeTicket
    {
        \assert($data instanceof PieceJointeTicket);

        $message = $data->getMessage();
        if ($message === null || $message->getTicket() === null) {
            throw new UnprocessableEntityHttpException('Message parent introuvable.');
        }

        $utilisateur = $this->security->getUser();
        \assert($utilisateur instanceof Utilisateur);

        $ticket = $message->getTicket();
        $codes = $this->calculateur->codesEffectifs($utilisateur, $this->contexte->idActif());
        $estAgent = $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n1')
            || $this->calculateur->autorise($codes, 'support', 'traiter_ticket_n2')
            || $this->calculateur->autorise($codes, 'support', 'administrer');
        $estDemandeur = $ticket->getDemandeur() !== null && (string) $ticket->getDemandeur()->getId() === (string) $utilisateur->getId();

        if (!$estAgent && !$estDemandeur) {
            throw new AccessDeniedHttpException("Vous n'avez pas accès à ce ticket.");
        }

        $this->em->persist($data);
        $this->em->flush();

        return $data;
    }
}
