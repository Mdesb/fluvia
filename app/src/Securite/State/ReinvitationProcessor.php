<?php

declare(strict_types=1);

namespace App\Securite\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Securite\Entity\Utilisateur;
use App\Securite\Enum\StatutUtilisateur;
use App\Securite\Notification\InvitationMailer;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Ré-invitation (§2.1 plan, cas limite spec §7) — `POST /utilisateurs/{id}/reinviter` : régénère
 * un jeton d'invitation (utile si l'ancien a expiré sans activation).
 *
 * @implements ProcessorInterface<Utilisateur, Utilisateur>
 */
final class ReinvitationProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly InvitationMailer $invitationMailer,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Utilisateur
    {
        \assert($data instanceof Utilisateur);

        $jetonClair = bin2hex(random_bytes(32));
        $data->setJetonInvitation(hash('sha256', $jetonClair));
        $data->setJetonInvitationExpire(new \DateTimeImmutable('+' . UtilisateurProcessor::DUREE_INVITATION_HEURES . ' hours'));
        $data->setStatut(StatutUtilisateur::Invite);
        $this->em->flush();

        $this->invitationMailer->envoyer($data, $jetonClair);

        return $data;
    }
}
