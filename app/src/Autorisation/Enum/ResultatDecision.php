<?php

declare(strict_types=1);

namespace App\Autorisation\Enum;

/**
 * Valeur de retour de `ServiceAutorisation::evaluer()` (RG-AUTZ-04). Non persistée en tant que
 * colonne : ne caractérise que le VO `Decision`.
 */
enum ResultatDecision: string
{
    case Autorise = 'autorise';
    case Refuse = 'refuse';
    case EscaladeRequise = 'escalade_requise';
}
