<?php

declare(strict_types=1);

namespace App\Boutique\Enum;

/** Statut de retrait physique porté par un BilletQrMeta (RG-M3-18, §4.13 spec-boutique.md). */
enum StatutRetraitPhysique: string
{
    case NonApplicable = 'non_applicable';
    case ARetirer = 'a_retirer';
    case Retire = 'retire';
}
