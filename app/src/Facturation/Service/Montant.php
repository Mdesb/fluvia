<?php

declare(strict_types=1);

namespace App\Facturation\Service;

/**
 * Arithmétique monétaire du module Facturation.
 *
 * Convention imposée par `plan-facturation.md` §1 : les montants du domaine Facturation sont des
 * `decimal(10,2)` sous forme de **chaîne** (même convention que M2/`Vente::$total`). La conversion
 * en **centimes** n'a lieu qu'à la frontière avec M6 (`EmettreFactureDirecteHandler`), où le moteur
 * d'écritures raisonne en entiers (`LigneEcriture::$debitCentimes`).
 *
 * Tous les calculs intermédiaires se font donc en **entiers de centimes** pour éviter toute dérive
 * flottante, puis sont reformatés en decimal à la sortie.
 */
final class Montant
{
    public const ZERO = '0.00';

    /** Convertit un decimal(10,2) (chaîne ou numérique) en centimes entiers. */
    public static function enCentimes(string|int|float $decimal): int
    {
        if (\is_int($decimal)) {
            return $decimal * 100;
        }

        return (int) round(((float) $decimal) * 100);
    }

    /** Reformate des centimes entiers en decimal(10,2) — toujours 2 décimales, point décimal. */
    public static function enDecimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    public static function ajouter(string $a, string $b): string
    {
        return self::enDecimal(self::enCentimes($a) + self::enCentimes($b));
    }

    public static function soustraire(string $a, string $b): string
    {
        return self::enDecimal(self::enCentimes($a) - self::enCentimes($b));
    }

    /** Applique un pourcentage (decimal, ex. « 30.00 ») à un montant, arrondi au centime supérieur/inférieur standard. */
    public static function pourcentage(string $montant, string $pourcentage): string
    {
        $centimes = self::enCentimes($montant);
        $resultat = (int) round($centimes * ((float) $pourcentage) / 100.0);

        return self::enDecimal($resultat);
    }

    /** Compare deux montants : -1, 0 ou 1. */
    public static function comparer(string $a, string $b): int
    {
        return self::enCentimes($a) <=> self::enCentimes($b);
    }

    public static function estZero(string $montant): bool
    {
        return self::enCentimes($montant) === 0;
    }

    /** Normalise une saisie libre en decimal(10,2). */
    public static function normaliser(string|int|float|null $valeur, string $defaut = self::ZERO): string
    {
        if ($valeur === null || $valeur === '') {
            return $defaut;
        }

        return self::enDecimal(self::enCentimes($valeur));
    }
}
