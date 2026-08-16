<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Cycle de vie d'un retrait click & collect (RG-M3-18, §6 spec-boutique.md). */
enum StatutRetraitClickCollect: string
{
    case ARetirer = 'a_retirer';
    case Retire = 'retire';
}
