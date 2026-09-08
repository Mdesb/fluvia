<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Enum;

/**
 * Ce qui rattache — ou non — une capacite aux neuf activites.
 *
 * ⚠ **CHACUNE DES VINGT-SIX CAPACITES EN RECOIT EXACTEMENT UNE.** C'est le point de tout ce
 * fichier. Une simple table « activite -> capacites » laisserait douze capacites SANS MENTION, et
 * « sans mention » se lit exactement comme « pas encore traitee » : on ne saurait plus distinguer un
 * oubli d'une decision. Classer les vingt-six, c'est transformer chaque silence en phrase.
 */
enum CapabilityCoverage
{
    /** Servie par au moins une des neuf activites : la deduction la propose. */
    case ByActivity;

    /** Aucune activite ne la sert, et pourtant tout exploitant l'a : elle s'ajoute a tous. */
    case Common;

    /**
     * Aucune activite ne la sert, et c'est le resultat attendu de D15, pas un trou.
     *
     * `specs/verticales/composition.md` classe le POSS et la securite du travailleur isole en
     * « ce qui n'entre dans aucune brique — une regle reellement nouvelle », le seul cas ou D15
     * autorise un module de code a subsister. La deduction ne les propose JAMAIS : un metier qui en
     * a besoin les recoit par son module, pas par une suggestion.
     */
    case OwnRule;

    /** Ce n'est pas un module vendable : la capacite porte le nom d'une verticale. */
    case Vertical;
}
