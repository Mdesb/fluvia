<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\DroitAcces;
use App\Acces\Entity\Equipement;

/**
 * Résolution des marges (§4.2 du plan, arbitrage point ouvert n°3) : la marge effective est
 * l'intersection de la fenêtre du droit (élargie de ses marges par défaut M1) et de la tolérance
 * locale de l'équipement. Un droit sans fenêtre définie (billet simple) n'est pas borné par les
 * marges — seule la fenêtre du droit, si présente, est contrainte.
 */
final class ResolveurMarges
{
    public function estDansMarges(DroitAcces $droit, Equipement $equipement, \DateTimeImmutable $instant): bool
    {
        $debut = $droit->getFenetreDebut();
        $fin = $droit->getFenetreFin();

        if ($debut === null && $fin === null) {
            return true; // Pas de fenêtre métier portée par le droit : aucune contrainte de marge.
        }

        $margeAvanceDroit = $droit->getMargeAvanceDefaut() ?? 0;
        $margeRetardDroit = $droit->getMargeRetardDefaut() ?? 0;
        $margeAvanceEquip = $equipement->getMargeAvance();
        $margeRetardEquip = $equipement->getMargeRetard();

        // Intersection : la fenêtre effective est la plus stricte des deux tolérances.
        $margeAvance = min($margeAvanceDroit, $margeAvanceEquip);
        $margeRetard = min($margeRetardDroit, $margeRetardEquip);

        if ($debut !== null) {
            $borneDebut = $debut->modify(sprintf('-%d minutes', $margeAvance));
            if ($instant < $borneDebut) {
                return false;
            }
        }
        if ($fin !== null) {
            $borneFin = $fin->modify(sprintf('+%d minutes', $margeRetard));
            if ($instant > $borneFin) {
                return false;
            }
        }

        return true;
    }
}
