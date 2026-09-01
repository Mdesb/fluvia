<?php

declare(strict_types=1);

namespace App\Dining\Domain;

use App\Dining\Entity\DiningOrderLine;
use App\Dining\Enum\LineStatus;
/**
 * L'addition d'une table (ACT-4, D16).
 *
 * **Deux totaux, et ils ne disent pas la même chose.** L'addition ne compte que ce que le client doit ;
 * la consommation compte tout ce qui est sorti de la cuisine, annulations comprises. Un plat renvoyé
 * disparaît du premier et reste dans le second — c'est exactement ce qui permet de comprendre une
 * perte en fin de service au lieu de la découvrir à l'inventaire.
 *
 * **Compté en centimes entiers**, comme partout ailleurs : `bcmath` n'est pas installé sur l'image, et
 * trente lignes de bar additionnées en flottants finissent par afficher un total faux d'un centime sur
 * une addition qu'un client relit devant vous. C'est la **sixième** copie de ces trois lignes dans le
 * dépôt (`Autorisation`, `Caution`, `Finance`, `Stay`, `Lodging`, ici) : je ne l'importe pas d'un autre
 * module (D2), et je redis à l'intégrateur qu'elles appellent une place dans `App\Platform`.
 */
final class DiningBill
{
    /** @param list<DiningOrderLine> $lignes */
    public function __construct(private readonly array $lignes)
    {
    }

    /** Ce que le client doit — les lignes annulées en sont sorties. */
    public function total(): string
    {
        return $this->sommer(static fn (DiningOrderLine $l): bool => $l->isBillable());
    }

    /**
     * Ce qui est sorti de la cuisine, **annulations comprises**.
     *
     * Toujours supérieur ou égal au total facturé. L'écart entre les deux est le coût du service.
     */
    public function consomme(): string
    {
        return $this->sommer(static fn (DiningOrderLine $l): bool => $l->hasConsumed());
    }

    /** Ce que le service a coûté sans être facturé — la perte du coup de feu. */
    public function perte(): string
    {
        return $this->sommer(
            static fn (DiningOrderLine $l): bool => $l->hasConsumed() && !$l->isBillable(),
        );
    }

    /** @param callable(OrderLine): bool $retenir */
    private function sommer(callable $retenir): string
    {
        $centimes = 0;
        foreach ($this->lignes as $ligne) {
            if ($retenir($ligne)) {
                $centimes += $ligne->getQuantity() * self::enCentimes($ligne->getUnitAmount());
            }
        }

        return number_format($centimes / 100, 2, '.', '');
    }

    /** `round()` avant le cast : `(int) (4.50 * 100)` vaut 449 sur certaines plateformes. */
    private static function enCentimes(string $montant): int
    {
        return (int) round(((float) $montant) * 100);
    }
}
