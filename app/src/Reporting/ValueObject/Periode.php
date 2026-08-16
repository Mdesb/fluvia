<?php

declare(strict_types=1);

namespace App\Reporting\ValueObject;

use App\Reporting\Enum\GranulariteMesure;

/**
 * Bornes de période partagées par les projections, l'agrégateur et les dashboards (§1.11
 * plan-reporting.md) : une seule définition des bornes jour/semaine/mois/année dans tout le
 * module (RG-M7-02). Immuable.
 */
final readonly class Periode
{
    public function __construct(
        public \DateTimeImmutable $debut,
        public \DateTimeImmutable $fin,
        public GranulariteMesure $granularite,
    ) {
    }

    public static function jour(?\DateTimeImmutable $reference = null): self
    {
        $reference ??= new \DateTimeImmutable('today');
        $debut = $reference->setTime(0, 0, 0);
        $fin = $reference->setTime(23, 59, 59);

        return new self($debut, $fin, GranulariteMesure::Jour);
    }

    public static function depuisDates(\DateTimeImmutable $debut, \DateTimeImmutable $fin, GranulariteMesure $granularite): self
    {
        return new self($debut->setTime(0, 0, 0), $fin->setTime(23, 59, 59), $granularite);
    }
}
