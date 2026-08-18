<?php

declare(strict_types=1);

namespace App\Autorisation\Service;

/**
 * Comparaison de montants decimal(10,2) sans dépendance à `ext-bcmath` (absente de cette image PHP
 * — vérifié : `php -m` ne liste pas `bcmath`, aucun autre module du dépôt n'appelle `bccomp()`).
 * Reprend exactement la conversion « centimes entiers » déjà utilisée par
 * `App\Vente\Service\PanierCalculateur::centimes()` (convention établie du dépôt pour éviter toute
 * comparaison flottante directe sur des montants) plutôt que d'introduire une dépendance système
 * supplémentaire pour ce seul module.
 */
final class ComparateurMontant
{
    private function __construct()
    {
    }

    public static function centimes(string $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }

    /** -1 si $a < $b, 0 si égaux, 1 si $a > $b (même contrat que `bccomp()`). */
    public static function comparer(string $a, string $b): int
    {
        return self::centimes($a) <=> self::centimes($b);
    }
}
