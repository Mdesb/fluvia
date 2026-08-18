<?php

declare(strict_types=1);

namespace App\Autorisation\Enum;

/**
 * Périmètre de cible d'une `LimiteAutorisation` (RG-AUTZ-05). V1 retient exclusivement
 * `PropreSession` (bornée à la session de caisse ouverte) plutôt qu'une variante « ventes du jour »
 * (⚠ HYPOTHÈSE §4.5 spec) — une valeur `propre_jour` pourra être ajoutée sans changement de structure.
 */
enum PerimetreAutorisation: string
{
    case PropreSession = 'propre_session';
    case PropreEtablissement = 'propre_etablissement';
    case Global = 'global';

    /**
     * Rang de priorité pour le départage entre limites de rôle à plafond égal (RG-AUTZ-03, ⚠
     * HYPOTHÈSE §2.3 plan) : le périmètre le plus étroit (le plus restrictif) l'emporte.
     */
    public function rang(): int
    {
        return match ($this) {
            self::PropreSession => 0,
            self::PropreEtablissement => 1,
            self::Global => 2,
        };
    }
}
