<?php

declare(strict_types=1);

namespace App\Personnel\Security;

use App\Personnel\Entity\Employe;
use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter « soi » (attribut `EMPLOYE_SOI`, patron `App\Reservation\Security\ReservationSoiVoter`) :
 * autorise si l'utilisateur connecté correspond à `Employe.utilisateur`. Un employé sans compte
 * `Utilisateur` (§3 spec) n'a par construction aucun accès `*_soi` — il n'utilise pas le logiciel.
 *
 * @extends Voter<string, Employe>
 */
final class EmployeSoiVoter extends Voter
{
    public const ATTRIBUTE = 'EMPLOYE_SOI';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && $subject instanceof Employe;
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        \assert($subject instanceof Employe);

        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof Utilisateur) {
            return false;
        }

        $lie = $subject->getUtilisateur();
        if ($lie === null) {
            return false;
        }

        return (string) $lie->getId() === (string) $utilisateur->getId();
    }
}
