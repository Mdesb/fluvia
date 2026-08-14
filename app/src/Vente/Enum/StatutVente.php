<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Statut d'une vente/ticket (cahier M2-§6). Après « validee », la vente est figée (NF525,
 * RG-M2-07/CA-15) : plus aucune modification de ligne/paiement, corrections par contre-passation.
 */
enum StatutVente: string
{
    case EnCours = 'en_cours';
    case Validee = 'validee';
    case Annulee = 'annulee';
    case AvoirEmis = 'avoir_emis';

    /** Vrai si la vente est scellée (validée ou issue d'une contre-passation) : immuable. */
    public function estScellee(): bool
    {
        return $this !== self::EnCours;
    }
}
