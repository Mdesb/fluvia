<?php

declare(strict_types=1);

namespace App\Securite\Security;

use App\Securite\Entity\Utilisateur;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Refuse l'authentification des comptes inactifs ou temporairement verrouillés (RG-SOCLE-06).
 */
final class VerificateurUtilisateur implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        if (!$user instanceof Utilisateur) {
            return;
        }

        if (!$user->isActif()) {
            throw new CustomUserMessageAccountStatusException('Compte inactif.');
        }

        if ($user->estVerrouille()) {
            throw new CustomUserMessageAccountStatusException('Compte temporairement verrouillé.');
        }
    }

    public function checkPostAuth(UserInterface $user): void
    {
        // Rien après authentification.
    }
}
