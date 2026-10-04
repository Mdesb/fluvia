<?php

declare(strict_types=1);

namespace App\Crm\Enum;

/**
 * État d'un consentement RGPD (RG-M4-07). `a_renouveler` = passage à la majorité (US-L5-10).
 *
 * `invalide` (#101) : un accord recueilli de façon non conforme, invalidé par une reprise — pas
 * « refusé » (la personne n'a rien refusé) ni « retiré » (elle n'a rien retiré). La ligne porte le
 * motif et la référence du lot ; la ligne d'origine reste en base, comme preuve de ce qui a été fait.
 * Réservé aux reprises : l'API de saisie le refuse.
 */
enum EtatConsentement: string
{
    case Accorde = 'accorde';
    case Refuse = 'refuse';
    case ARenouveler = 'a_renouveler';
    case Expire = 'expire';
    case Invalide = 'invalide';
}
