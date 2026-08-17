<?php

declare(strict_types=1);

namespace App\Caution\Enum;

/**
 * Statut d'une caution générique (module `App\Caution`, socle du patron dépôt/consignation
 * dupliqué jusqu'ici par `App\Piscine` (casiers), `App\Padel` (matériel) et `App\Patinoire`
 * (patins) : voir refactor lot caution générique). Superset des enums locaux des 3 verticales
 * (Piscine/Padel : 3 valeurs « encaissee/liberee/retenue » ; Patinoire : 4 valeurs avec retenue
 * partielle/totale) — les verticales à granularité plus fine mappent leur propre lecture (ex.
 * Piscine/Padel traitent `RetenueTotale` comme leur unique valeur « retenue »).
 */
enum StatutCaution: string
{
    case Consignee = 'consignee';
    case Restituee = 'restituee';
    case RetenuePartielle = 'retenue_partielle';
    case RetenueTotale = 'retenue_totale';
}
