<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/** Rôle d'un client au sein d'une famille (RG-M4-02) : payeur ≠ bénéficiaire, cumul possible. */
enum RoleBeneficiaire: string
{
    case Payeur = 'payeur';
    case Beneficiaire = 'beneficiaire';
    case PayeurEtBeneficiaire = 'payeur_et_beneficiaire';
}
