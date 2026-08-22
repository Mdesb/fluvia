<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Où se prend la décision d'ouvrir (D17, axe 1). C'est l'axe le plus structurant des quatre : il
 * détermine à lui seul si une révocation peut être immédiate, et donc ce que l'on peut promettre à
 * l'exploitant.
 */
enum DecisionPoint: string
{
    /** Le serveur tranche à chaque passage. Révocation immédiate possible, mais le réseau est requis. */
    case Server = 'server';

    /**
     * L'unité de traitement locale tranche depuis sa base embarquée. Elle continue de fonctionner
     * hors ligne, et une liste de révocation lui est poussée à la synchronisation suivante — c'est le
     * modèle de nos portiques et tourniquets actuels.
     */
    case Controller = 'controller';

    /**
     * Le support **porte** l'autorisation ; la serrure valide seule, sans réseau ni serveur. Modèle
     * des serrures autonomes sur pile (chambres d'hôtel). Conséquence à ne jamais masquer : on ne peut
     * rien pousser à une serrure qu'on ne joint pas, donc la révocation y est impossible au sens
     * strict et se contourne par des validités courtes.
     */
    case Credential = 'credential';
}
