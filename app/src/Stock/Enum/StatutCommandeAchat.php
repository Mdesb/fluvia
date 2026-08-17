<?php

declare(strict_types=1);

namespace App\Stock\Enum;

/** Cycle de vie d'une commande d'achat (RG-STOCK-04, §6 du plan). */
enum StatutCommandeAchat: string
{
    case Brouillon = 'brouillon';
    case Envoyee = 'envoyee';
    case Confirmee = 'confirmee';
    case PartiellementRecue = 'partiellement_recue';
    case Recue = 'recue';
    case Cloturee = 'cloturee';
    case Annulee = 'annulee';
}
