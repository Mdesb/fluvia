<?php

declare(strict_types=1);

namespace App\Lodging\Domain;

/**
 * Le tarif d'une période d'hébergement, **nuit par nuit** (ACT-2, D16).
 *
 * **Un prix par nuit, pas un prix par séjour.** L'hôtellerie et le camping tarifent la nuit : le 14
 * juillet ne vaut pas un mardi de novembre, et un séjour à cheval sur les deux ne peut pas s'exprimer
 * par un montant unique multiplié. Un module qui ne saurait dire qu'un total serait inutilisable dès
 * la première haute saison — et c'est justement la saison qui compte.
 *
 * **Le tarif par défaut existe pour que l'absence de grille ne soit pas un blocage** : un camping qui
 * ouvre son premier emplacement doit pouvoir vendre avant d'avoir saisi 365 lignes.
 *
 * **Les montants sont des chaînes décimales et l'addition se fait en centimes entiers.** `bcmath`
 * n'est pas installé sur l'image du projet (vérifié), et additionner trente nuits en flottants finit
 * par afficher un total faux d'un centime sur une facture qu'un client relit. C'est la **quatrième**
 * copie de ces trois lignes dans le dépôt (`Autorisation`, `Caution`, `Finance`, `Stay`) : je ne
 * l'importe pas d'un autre module (D2 interdit l'appel direct), et je signale à l'intégrateur qu'elles
 * appellent une place commune dans `App\Platform`.
 */
final class NightlyRates
{
    /** @var array<string, string> tarifs par date « Y-m-d » */
    private array $rates;

    /**
     * @param array<string, string> $rates       tarifs exceptionnels, indexés par date « Y-m-d »
     * @param string                $defaultRate tarif appliqué à toute nuit sans tarif propre
     */
    public function __construct(array $rates, private readonly string $defaultRate)
    {
        $this->rates = $rates;
    }

    public function rateFor(\DateTimeImmutable $night): string
    {
        return $this->rates[$night->format('Y-m-d')] ?? $this->defaultRate;
    }

    /** Le total de la période, au format décimal du dépôt (`'420.00'`). */
    public function totalFor(LodgingPeriod $period): string
    {
        $centimes = 0;
        foreach ($period->nights() as $nuit) {
            $centimes += self::toCents($this->rateFor($nuit));
        }

        return number_format($centimes / 100, 2, '.', '');
    }

    /**
     * Le détail nuit par nuit — ce qui s'imprime sur la note et ce qu'un client conteste.
     *
     * @return list<array{night: string, rate: string}>
     */
    public function breakdownFor(LodgingPeriod $period): array
    {
        return array_map(
            fn (\DateTimeImmutable $nuit): array => [
                'night' => $nuit->format('Y-m-d'),
                'rate' => $this->rateFor($nuit),
            ],
            $period->nights(),
        );
    }

    /** `round()` avant le cast : `(int) (4.50 * 100)` vaut 449 sur certaines plateformes. */
    private static function toCents(string $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
