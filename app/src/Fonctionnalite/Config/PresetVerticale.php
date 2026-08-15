<?php

declare(strict_types=1);

namespace App\Fonctionnalite\Config;

use App\Fonctionnalite\Enum\CapaciteCode;
use App\Fonctionnalite\Enum\Metier;

/**
 * Preset de capacités par verticale métier — configuration PHP (pas un `if` métier éparpillé,
 * §4 constitution.md), appliqué à un établissement via `App\Fonctionnalite\Service\Fonctionnalites::appliquerPreset()`.
 *
 * ⚠ HYPOTHÈSE (aucune source ne fige cette liste) : les jeux de capacités par verticale sont déduits
 * des modules déjà livrés (Piscine L6, Sport L-Sport) pour `piscine`/`sport` ; `padel`/`patinoire`/
 * `musee` (verticales non encore construites, cf. §5 constitution.md) reçoivent un jeu plausible par
 * analogie, à confirmer avec IT Cotation avant construction réelle de ces verticales.
 */
final class PresetVerticale
{
    /** @var array<string, list<string>> */
    private const CAPACITES = [
        // Piscine (L6) : POSS, bassins/créneaux, casiers/caution, encadrants MNS/BNSSA, SEPA/recouvrement
        // (régie), porte-monnaie (RG-M4-03).
        'piscine' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::Poss->value,
            CapaciteCode::Casiers->value,
            CapaciteCode::Encadrants->value,
            CapaciteCode::PorteMonnaie->value,
            CapaciteCode::Sepa->value,
            CapaciteCode::Recouvrement->value,
        ],
        // Sport/Fitness : abonnement + anti-impayés couplé à l'accès, SEPA, accès nocturne, no-show
        // (réservation de cours).
        'sport' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Sepa->value,
            CapaciteCode::Recouvrement->value,
            CapaciteCode::AccesNocturne->value,
            CapaciteCode::PorteMonnaie->value,
            CapaciteCode::NoShow->value,
        ],
        // Padel : réservation de terrain à forte volumétrie, no-show pénalisant, vente à distance.
        'padel' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::NoShow->value,
            CapaciteCode::BoutiqueEnLigne->value,
            CapaciteCode::PorteMonnaie->value,
        ],
        // Patinoire : créneaux publics/scolaires, casiers, location de patins, encadrants.
        'patinoire' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::Casiers->value,
            CapaciteCode::LocationMateriel->value,
            CapaciteCode::Encadrants->value,
        ],
        // Musée : billetterie/créneaux de visite, vente à distance ; pas de casiers/SEPA par défaut.
        'musee' => [
            CapaciteCode::ControleAcces->value,
            CapaciteCode::Reservation->value,
            CapaciteCode::BoutiqueEnLigne->value,
        ],
    ];

    /** @return list<string> */
    public static function capacites(Metier $metier): array
    {
        return self::CAPACITES[$metier->value] ?? [];
    }
}
