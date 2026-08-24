<?php

declare(strict_types=1);

namespace App\Stay\Service;

/**
 * Le solde d'un séjour, calculé **en centimes entiers** (ACT-3).
 *
 * **Pourquoi pas `bcadd`** : `bcmath` n'est pas installé sur l'image PHP du projet — vérifié, pas
 * supposé. **Pourquoi pas des flottants** : additionner cent lignes de bar en `float` finit par
 * afficher `137.99999999999997` sur une note qu'un client relit au comptoir.
 *
 * **Pourquoi une copie du convertisseur et non un appel à `App\Autorisation\ComparateurMontant`** :
 * D2 interdit l'appel direct de module à module, et le dépôt porte déjà trois copies de ces trois
 * lignes (`Autorisation`, `Caution`, `Finance`). Une quatrième reste préférable à une dépendance de
 * `Stay` vers `Autorisation`, qui n'a aucun sens métier. Si ces copies doivent converger un jour,
 * c'est vers `App\Platform` — décision de l'intégrateur, pas la mienne.
 */
final class StayBalance
{
    private function __construct(
        public readonly int $totalCents,
        public readonly int $lineCount,
    ) {
    }

    /** @param list<string> $amounts montants décimaux tels que stockés (`'9.00'`) */
    public static function fromAmounts(array $amounts): self
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += self::toCents($amount);
        }

        return new self($total, \count($amounts));
    }

    public static function empty(): self
    {
        return new self(0, 0);
    }

    /** Le total, au format décimal du dépôt — c'est ce qui s'imprime sur la note. */
    public function total(): string
    {
        return number_format($this->totalCents / 100, 2, '.', '');
    }

    public function isZero(): bool
    {
        return 0 === $this->totalCents;
    }

    /**
     * `round()` avant le cast : `(int) (4.50 * 100)` vaut **449** sur certaines plateformes, parce que
     * 4.50 n'est pas représentable exactement en binaire. C'est le centime perdu classique, et il ne se
     * voit qu'en production sur une note à trente lignes.
     */
    private static function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
