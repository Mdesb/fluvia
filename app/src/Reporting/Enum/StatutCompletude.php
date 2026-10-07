<?php

declare(strict_types=1);

namespace App\Reporting\Enum;

/** Marquage de complétude d'une `Mesure` consolidée (RG-M7-08, RG-REPORT-11) — jamais silencieux. */
enum StatutCompletude: string
{
    case Complet = 'complet';
    case Partiel = 'partiel';

    /**
     * IL N'Y A PAS DE SOURCE A INTERROGER SUR CE SITE — ce n'est pas un defaut de remontee.
     *
     * Distinct de `Partiel`, qui signifie « une source devait repondre et n'a pas repondu »
     * et suppose donc un incident passager. Un musee sans tourniquet n'est pas en panne :
     * il n'est pas equipe, et sa frequentation n'est pas mesurable par cette voie.
     *
     * Arbitrage de Maxime du 15/09 (point n7 de `COORDINATION/A-REVOIR.md`), apres mesure :
     * le rattrapage du 15/09 avait ecrit 77 mesures `complet` a 0,00 sur sept sites sans
     * controleur d'acces — dont un musee et une patinoire.
     */
    case NonInstrumente = 'non_instrumente';
}
