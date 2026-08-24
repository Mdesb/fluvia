<?php

declare(strict_types=1);

namespace App\Reservation\Service;

use App\Reservation\Entity\Ressource;

/**
 * Maintient `Ressource.occupationCourante` sur la ressource **porteuse** de la jauge (RG-M5-08) :
 * elle-même si non partageable / sans mère, sinon sa `ressourceMere`. Garantit que toute réservation
 * qui dépasserait la jauge globale est refusée même si un Créneau individuel a encore de la place
 * (CA-14).
 *
 * **ACT-1 / D16 point 1** — le compteur bouge de la **quantité** de la réservation, pas de 1. Le
 * défaut `$quantite = 1` n'est pas un confort d'appel : il rend la bascule exactement neutre pour
 * tout appelant antérieur à ce lot, et `jaugeDepassee()` sans argument garde son sens d'origine
 * (`occupation >= capacité` ⟺ `occupation + 1 > capacité`).
 */
final class JaugeRessourceMereHandler
{
    public function incrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $porteuse->setOccupationCourante($porteuse->getOccupationCourante() + $quantite);
    }

    public function decrementer(Ressource $ressource, int $quantite = 1): void
    {
        $porteuse = $ressource->ressourcePorteuseJauge();
        $porteuse->setOccupationCourante(max(0, $porteuse->getOccupationCourante() - $quantite));
    }

    /**
     * `$quantiteDemandee` est ce qu'on s'apprête à poser sur la jauge, pas ce qui s'y trouve déjà :
     * la question est « est-ce que cette demande **ferait** déborder », et une école à deux places
     * libres doit refuser un groupe de cinq sans être complète.
     */
    public function jaugeDepassee(Ressource $ressource, int $quantiteDemandee = 1): bool
    {
        $porteuse = $ressource->ressourcePorteuseJauge();

        return $porteuse->getOccupationCourante() + $quantiteDemandee > $porteuse->getCapacitePropre();
    }
}
