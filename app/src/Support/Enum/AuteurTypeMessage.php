<?php

declare(strict_types=1);

namespace App\Support\Enum;

/** Type d'auteur d'un `MessageTicket` (RG-SUP-12). */
enum AuteurTypeMessage: string
{
    case Demandeur = 'demandeur';
    case Agent = 'agent';
}
