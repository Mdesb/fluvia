<?php

declare(strict_types=1);

namespace App\Personnel\Enum;

/** Type de contrat de l'Employé (RG-PERSO-01) — liste ouverte ⚠ hypothèse spec §4.1/§5. */
enum TypeContrat: string
{
    case Cdi = 'cdi';
    case Cdd = 'cdd';
    case Vacataire = 'vacataire';
    case Saisonnier = 'saisonnier';
    case Stagiaire = 'stagiaire';
    case Prestataire = 'prestataire';
}
