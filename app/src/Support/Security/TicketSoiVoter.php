<?php

declare(strict_types=1);

namespace App\Support\Security;

use App\Securite\Entity\Utilisateur;
use App\Support\Entity\TicketSupport;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * `TICKET_SOI` (§4 plan-support.md) — `ticket.demandeur === utilisateur courant`, patron
 * `App\Personnel\Security\EmployeSoiVoter`/`App\Reservation\Security\ReservationSoiVoter`.
 *
 * @extends Voter<string, TicketSupport>
 */
final class TicketSoiVoter extends Voter
{
    public const ATTRIBUTE = 'TICKET_SOI';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof TicketSupport;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof TicketSupport);
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $demandeur = $subject->getDemandeur();

        return $demandeur !== null && (string) $demandeur->getId() === (string) $utilisateur->getId();
    }
}
