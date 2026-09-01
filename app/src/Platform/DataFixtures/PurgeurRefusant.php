<?php

declare(strict_types=1);

namespace App\Platform\DataFixtures;

use Doctrine\Common\DataFixtures\Purger\ORMPurgerInterface;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Purgeur qui ne purge pas : il refuse, en disant quoi faire à la place.
 *
 * Rendu par {@see PurgeurInterditHorsDeveloppement} hors `dev` et `test`. Il n'est atteint que si
 * quelqu'un lance un chargement **avec purge** sur une base qui n'est pas jetable — le seul cas où le
 * silence coûterait cher. Un chargement additif ne l'appelle jamais.
 *
 * **Il implémente `ORMPurgerInterface` et non `PurgerInterface`, et ce n'est pas cosmétique.**
 * `ORMExecutor` type son second argument sur l'interface ORM : un purgeur qui ne l'implémente pas fait
 * échouer la commande sur une erreur de type, avant même d'arriver ici. Le refus serait alors illisible
 * — un message du moteur PHP au lieu de l'explication qu'on veut donner. Constaté à l'essai.
 */
final class PurgeurRefusant implements ORMPurgerInterface
{
    public function __construct(private readonly string $environnement)
    {
    }

    /** Exigé par l'interface ORM ; sans effet, ce purgeur ne purgeant jamais. */
    public function setEntityManager(EntityManagerInterface $em): void
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
