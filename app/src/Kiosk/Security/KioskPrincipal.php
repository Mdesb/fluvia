<?php

declare(strict_types=1);

namespace App\Kiosk\Security;

use App\Kiosk\Entity\Kiosk;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * L'identité d'un APPAREIL de borne, résolue par `KioskAuthenticator`. Distincte par construction de
 * `App\Securite\Entity\Utilisateur` : elle ne porte aucune permission humaine (`PERM`), seulement le
 * rôle `ROLE_KIOSK`, et elle n'existe que sur le pare-feu `kiosk` (`^/api/kiosk/`). Un jeton de borne
 * présenté à une route du back-office n'y est même pas lu : le pare-feu `api` attend un JWT.
 */
final class KioskPrincipal implements UserInterface
{
    public function __construct(
        public readonly Kiosk $kiosk,
    ) {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return ['ROLE_KIOSK'];
    }

    public function eraseCredentials(): void
    {
    }

    public function getUserIdentifier(): string
    {
        return 'kiosk:' . $this->kiosk->getId();
    }
}
