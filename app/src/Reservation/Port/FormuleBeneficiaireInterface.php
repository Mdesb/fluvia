<?php

declare(strict_types=1);

namespace App\Reservation\Port;

use App\Offre\Entity\ServiceInclus;
use Symfony\Component\Uid\Uuid;

/**
 * Frontière M1/M4 (Risque n°5 du plan) : « bénéficiaire détient une formule active couvrant cette
 * Activité ». Aucune modélisation explicite de ce rattachement n'existe encore côté M1/M4 — ce port
 * est stubé (`FormuleBeneficiaireStub`, renvoie systématiquement `null` par défaut, conséquence :
 * toute réservation bascule par défaut en vente à l'unité, `RG-M5-02`) tant qu'un lot M1/M4 dédié ne
 * modélise pas ce rattachement.
 */
interface FormuleBeneficiaireInterface
{
    /** ServiceInclus (M1) couvrant l'activité pour ce bénéficiaire, ou null si aucune formule active. */
    public function serviceInclus(Uuid $beneficiaireId, Uuid $activiteId): ?ServiceInclus;

    /** @return list<\DateTimeImmutable> Dates de consommation déjà décomptées pour ce service inclus. */
    public function consommations(Uuid $beneficiaireId, Uuid $serviceInclusId): array;
}
