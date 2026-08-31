<?php

declare(strict_types=1);

namespace App\Platform\DataFixtures;

use Doctrine\Common\DataFixtures\Purger\PurgerInterface;

/**
 * Purgeur qui ne purge pas : il refuse, en disant quoi faire à la place.
 *
 * Rendu par {@see PurgeurInterditHorsDeveloppement} hors `dev` et `test`. Il n'est atteint que si
 * quelqu'un lance un chargement **avec purge** sur une base qui n'est pas jetable — le seul cas où le
 * silence coûterait cher. Un chargement additif ne l'appelle jamais.
 */
final class PurgeurRefusant implements PurgerInterface
{
    public function __construct(private readonly string $environnement)
    {
    }

    public function purge(): void
    {
        throw new \RuntimeException(sprintf(
            "Purge refusée en environnement « %s » : cette commande viderait la base avant d'écrire.\n"
            ."C'est ce qui a mis les rôles de la préproduction à zéro droit le 24/08.\n"
            ."Pour recharger la démonstration sans rien détruire : php bin/console app:demo:charger",
            $this->environnement,
        ));
    }
}
