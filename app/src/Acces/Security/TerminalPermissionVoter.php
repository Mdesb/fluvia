<?php

declare(strict_types=1);

namespace App\Acces\Security;

use App\Acces\Enum\StatutTerminal;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Voter dédié aux permissions d'un `Terminal` (plan-acces-terminal.md §3.2). Attribut **`PERM_TERMINAL`**
 * — délibérément distinct de `PERM` (`App\Securite\Security\PermissionVoter`) pour ne **jamais**
 * intersecter avec le RBAC humain : un `Terminal` n'a que deux capacités fixes, pas de rôles/affectations.
 *
 * @extends Voter<string, string>
 */
final class TerminalPermissionVoter extends Voter
{
    public const ATTRIBUTE = 'PERM_TERMINAL';

    /** @var list<string> */
    private const PERMISSIONS_FIXES = ['acces.ingestion', 'acces.snapshot'];

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::ATTRIBUTE && \is_string($subject);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $utilisateur = $token->getUser();
        if (!$utilisateur instanceof TerminalUtilisateur) {
            return false;
        }
        if ($utilisateur->terminal->getStatut() !== StatutTerminal::Actif) {
            return false;
        }

        return \in_array($subject, self::PERMISSIONS_FIXES, true);
    }
}
