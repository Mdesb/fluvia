<?php

declare(strict_types=1);

namespace App\Stock\Service;

/**
 * Arithmétique décimale exacte sans dépendance bcmath (non installée dans ce dépôt, cf.
 * `App\Vente\Service\PanierCalculateur` qui applique la même stratégie « entiers à l'échelle » pour
 * les montants). Les quantités/coûts sont convertis en entiers à une échelle fixe (ex. millièmes pour
 * une quantité `decimal(12,3)`), opérés en entier (aucune perte de précision flottante), puis
 * reconvertis en chaîne décimale. Utilisé par `MoteurValorisationFifoLifo` pour reproduire
 * exactement les exemples chiffrés FIFO/LIFO de la spec (§4.5).
 */
final class ArithmetiqueDecimale
{
    /** Convertit une chaîne décimale (ex. "4.50") en entier à l'échelle donnée (ex. 45000 à l'échelle 4). */
    public static function versEntier(string $decimal, int $echelle): int
    {
        $decimal = trim($decimal);
        $negatif = str_starts_with($decimal, '-');
        $decimal = ltrim($decimal, '+-');
        [$entier, $frac] = array_pad(explode('.', $decimal, 2), 2, '');
        $frac = str_pad(substr($frac, 0, $echelle), $echelle, '0');
        $valeur = ((int) $entier) * (10 ** $echelle) + (int) $frac;

        return $negatif ? -$valeur : $valeur;
    }

    /** Reconvertit un entier à l'échelle donnée en chaîne décimale (ex. 45000 à l'échelle 4 → "4.5000"). */
    public static function versDecimal(int $valeur, int $echelle): string
    {
        $negatif = $valeur < 0;
        $valeur = abs($valeur);
        $diviseur = 10 ** $echelle;
        $entier = intdiv($valeur, $diviseur);
        $frac = $valeur % $diviseur;
        $texte = $entier . '.' . str_pad((string) $frac, $echelle, '0', STR_PAD_LEFT);

        return $negatif ? '-' . $texte : $texte;
    }

    /**
     * Produit exact `quantité (échelle 3) × coût unitaire (échelle 4)` arrondi à l'échelle 2
     * (montant en euros, decimal(12,2)) — cf. exemples chiffrés spec §4.5 (490.00 / 505.00 / 135.00 /
     * 120.00). Arrondi demi-au-supérieur sur le residu (cas rares de coûts non ronds).
     */
    public static function multiplierVersMontant(string $quantite, string $coutUnitaire): string
    {
        $qte = self::versEntier($quantite, 3);
        $cout = self::versEntier($coutUnitaire, 4);
        $produit = $qte * $cout; // échelle 7
        $diviseur = 100000; // 10^(7-2)
        $quotient = intdiv(abs($produit), $diviseur);
        $reste = abs($produit) % $diviseur;
        if ($reste * 2 >= $diviseur) {
            ++$quotient;
        }
        $signe = $produit < 0 ? -1 : 1;

        return self::versDecimal($signe * $quotient, 2);
    }

    public static function additionner(string $a, string $b, int $echelle): string
    {
        return self::versDecimal(self::versEntier($a, $echelle) + self::versEntier($b, $echelle), $echelle);
    }

    public static function soustraire(string $a, string $b, int $echelle): string
    {
        return self::versDecimal(self::versEntier($a, $echelle) - self::versEntier($b, $echelle), $echelle);
    }

    public static function estPositif(string $decimal, int $echelle): bool
    {
        return self::versEntier($decimal, $echelle) > 0;
    }

    public static function comparer(string $a, string $b, int $echelle): int
    {
        return self::versEntier($a, $echelle) <=> self::versEntier($b, $echelle);
    }
}
