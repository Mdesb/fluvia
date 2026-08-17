<?php

declare(strict_types=1);

namespace App\Acces\Security;

use App\Acces\Entity\Terminal;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Wrapper léger d'authentification machine (US-TERM-01/02, plan-acces-terminal.md §3.1) : porte la
 * référence au `Terminal` résolu par `TerminalAuthenticator`. Distinct par construction de
 * `App\Securite\Entity\Utilisateur` — un jeton `Terminal` ne débloque jamais les permissions humaines
 * (`PERM`/`PermissionVoter`), seulement `PERM_TERMINAL` (`TerminalPermissionVoter`).
 */
final class TerminalUtilisateur implements UserInterface
{
    public function __construct(
        public readonly Terminal $terminal,
    ) {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_TERMINAL'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->terminal->getId();
    }
}
