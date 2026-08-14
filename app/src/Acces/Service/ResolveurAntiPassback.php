<?php

declare(strict_types=1);

namespace App\Acces\Service;

use App\Acces\Entity\Equipement;

/**
 * Résolution de l'anti-passback par spécificité croissante (§4.1 du plan, arbitrage point ouvert
 * n°4) : défaut système (~5 min) → espace → équipement, le plus spécifique gagnant.
 */
final class ResolveurAntiPassback
{
    private const DEFAUT_DELAI = 300;
    private const DEFAUT_ACTIF = true;

    /** @return array{actif: bool, delai: int} */
    public function resoudre(Equipement $equipement): array
    {
        $espace = $equipement->getControleur()?->getEspace();

        $actif = $espace?->isAntiPassbackActif() ?? self::DEFAUT_ACTIF;
        $delai = $espace?->getAntiPassbackDelai() ?? self::DEFAUT_DELAI;

        if ($equipement->getAntiPassbackActif() !== null) {
            $actif = $equipement->getAntiPassbackActif();
        }
        if ($equipement->getAntiPassbackDelai() !== null) {
            $delai = $equipement->getAntiPassbackDelai();
        }

        return ['actif' => $actif, 'delai' => $delai];
    }
}
