<?php

declare(strict_types=1);

namespace App\Acces\Enum;

/**
 * Motif structuré d'une décision de passage (filtrage journal, CA-3/12). `FederationInactive` est une
 * extension locale (non littérale dans la spec) pour tracer distinctement un refus de fédération
 * inter-entités désactivée (US-L3-12, CA-13).
 */
enum CodeMotifRefus: string
{
    case HorsMarge = 'hors_marge';
    case AntiPassback = 'anti_passback';
    case CreditEpuise = 'credit_epuise';
    case SupportBloque = 'support_bloque';
    case SeuilFmi = 'seuil_fmi';
    case DroitInvalide = 'droit_invalide';
    case SensInterdit = 'sens_interdit';
    case NonNominatif = 'non_nominatif';
    case OuvertureManuelle = 'ouverture_manuelle';
    case FederationInactive = 'federation_inactive';
    /** Code de support signé (format `App\Vente\Service\GenerateurCodeSupport`) dont la signature HMAC
     *  ne correspond pas : code forgé/altéré, refusé avant toute résolution en base. */
    case SignatureInvalide = 'signature_invalide';
}
