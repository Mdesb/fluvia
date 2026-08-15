<?php

declare(strict_types=1);

namespace App\Crm\Enum;

enum TypeMouvementPmv: string
{
    case Recharge = 'recharge';
    case DebitVente = 'debit_vente';
    case RemboursementVente = 'remboursement_vente';
    case Expiration = 'expiration';
    case Ajustement = 'ajustement';
}
