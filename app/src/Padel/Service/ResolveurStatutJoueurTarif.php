<?php

declare(strict_types=1);

namespace App\Padel\Service;

use App\Crm\Entity\Beneficiaire;
use App\Padel\Enum\StatutJoueurTarif;

/**
 * Détermine le statut membre/non-membre d'un joueur pour la tarification (RG-PADEL-02). ⚠ Aucun
 * concept « adhésion active » n'est modélisé côté M1/M4 à ce jour (même limite que le Risque n°5 du
 * plan-reservation.md, hérité en Risque n°3 du plan-padel) : par défaut tous les joueurs sont
 * `non_membre` tant qu'un lot M1/M4 dédié ne modélise pas ce rattachement. `definir()` permet aux
 * tests d'injecter un statut membre (patron `FormuleBeneficiaireStub`).
 */
final class ResolveurStatutJoueurTarif
{
    /** @var array<string, StatutJoueurTarif> */
    private array $statutsForces = [];

    public function resoudre(Beneficiaire $joueur): StatutJoueurTarif
    {
        return $this->statutsForces[(string) $joueur->getId()] ?? StatutJoueurTarif::NonMembre;
    }

    /** Réservé aux tests : force le statut d'un joueur. */
    public function definir(Beneficiaire $joueur, StatutJoueurTarif $statut): void
    {
        $this->statutsForces[(string) $joueur->getId()] = $statut;
    }
}
