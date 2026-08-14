<?php

declare(strict_types=1);

namespace App\Vente\Enum;

/**
 * Résultat d'une transaction TPE (US-L2-07 / CA-10). Seul « accepte » crée un règlement ;
 * un refus/annulation/timeout n'ajoute aucun règlement et laisse le reste dû inchangé.
 */
enum StatutTPE: string
{
    case Accepte = 'accepte';
    case Refuse = 'refuse';
    case Annule = 'annule';
    case Timeout = 'timeout';
}
