<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/** Ce qu'un pilote sait faire d'une révocation (D17, axe 2). */
enum RevocationCapability: string
{
    /** Effet immédiat : le passage suivant est refusé. */
    case Immediate = 'immediate';

    /** Effet à la prochaine synchronisation. L'exploitant doit le savoir : ce n'est pas « tout de suite ». */
    case Deferred = 'deferred';

    /**
     * Aucun effet possible. Le support porte son autorisation et rien ne peut la lui retirer à
     * distance. Ne jamais afficher « accès révoqué » dans ce cas — ce serait un mensonge à
     * l'exploitant, et le genre de mensonge qui se découvre sur incident.
     */
    case Impossible = 'impossible';
}
