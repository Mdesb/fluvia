<?php

declare(strict_types=1);

namespace App\Boutique\Security;

use App\Boutique\Entity\DemandeRemboursement;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Vérifie que la `Vente` d'origine de la demande appartient au client connecté (`clientLie`), même
 * patron que `App\Reservation\Security\ReservationSoiVoter` (code réel).
 *
 * @extends Voter<string, DemandeRemboursement>
 */
final class DemandeRemboursementSoiVoter extends Voter
{
    public const ATTRIBUTE = 'DEMANDE_REMB_SOI';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof DemandeRemboursement;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof DemandeRemboursement);
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $clientLie = $utilisateur->getClientLie();
        $venteClient = $subject->getVente()?->getClient();

        return $clientLie !== null && $venteClient !== null && (string) $clientLie === (string) $venteClient;
    }
}
