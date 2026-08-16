<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Ressource;

/**
 * Maintient `Ressource.occupationCourante` sur la ressource **porteuse** de la jauge (RG-M5-08) :
 * elle-même si non partageable / sans mère, sinon sa `ressourceMere`. Garantit que toute réservation
 * qui dépasserait la jauge globale est refusée même si un Créneau individuel a encore de la place
 * (CA-14).
 */
final class JaugeRessourceMereHandler
{
    public function incrementer(Ressource $ressource): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $porteuse->setOccupationCourante($porteuse->getOccupationCourante() + 1);
    }

    public function decrementer(Ressource $ressource): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $porteuse->setOccupationCourante(max(0, $porteuse->getOccupationCourante() - 1));
    }

    public function jaugeDepassee(Ressource $ressource): bool
    {
        $porteuse = $ressource->ressourcePorteuseJauge();

        return $porteuse->getOccupationCourante() >= $porteuse->getCapacitePropre();
    }
}
