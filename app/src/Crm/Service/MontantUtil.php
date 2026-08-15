<?php

declare(strict_types=1);

namespace App\Crm\Service;

/**
 * Arithmétique monétaire en centimes (évite bcmath, non installé — même patron que
 * `App\Vente\Service\PanierCalculateur`). Tous les montants PMV sont manipulés en centimes puis
 * reconvertis en decimal(10,2) pour la persistance.
 */
final class MontantUtil
{
    public static function centimes(string $decimal): int
    {
        return (int) round(((float) $decimal) * 100);
    }

    public static function decimal(int $centimes): string
    {
        return number_format($centimes / 100, 2, '.', '');
    }

    public static function addition(string $a, string $b): string
    {
        return self::decimal(self::centimes($a) + self::centimes($b));
    }

    public static function soustraction(string $a, string $b): string
    {
        return self::decimal(self::centimes($a) - self::centimes($b));
    }

    public static function comparer(string $a, string $b): int
    {
        return self::centimes($a) <=> self::centimes($b);
    }
}
