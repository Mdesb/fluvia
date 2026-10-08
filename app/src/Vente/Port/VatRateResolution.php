<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Le taux résolu pour une ligne, ou la raison pour laquelle il ne l'est pas.
 *
 * Résolu : la valeur, la catégorie EN 16931 (nulle quand le taux n'en déclare pas, D66-ter), le
 * libellé et la référence du `TauxTva`. Ce sont des copies : la ligne les grave, et un `PATCH` du
 * taux ensuite ne la touche pas (spec, F-2).
 *
 * Non résolu : `refusal` dit ce qui manque, en nommant la catégorie ou le site, pour que la personne
 * qui reçoit le refus sache quoi corriger.
 */
final readonly class VatRateResolution
{
    private function __construct(
        public ?string $rate,
        public ?string $category,
        public ?string $label,
        public ?Uuid $rateRef,
        public ?string $refusal,
    ) {
    }

    public static function resolved(string $rate, ?string $category, string $label, Uuid $rateRef): self
    {
        return new self($rate, $category, $label, $rateRef, null);
    }

    public static function missing(string $refusal): self
    {
        return new self(null, null, null, null, $refusal);
    }

    public function isResolved(): bool
    {
        return $this->refusal === null;
    }
}
