<?php

declare(strict_types=1);

namespace App\Acces\Dto;

/**
 * Projection calculée à la volée pour le snapshot terminal (US-TERM-03/04/05, plan-acces-terminal.md
 * §1.3) à partir de `Support` + `Appairage` (actif) + `DroitAcces`. Aucune table dédiée : la « version »
 * de coupure (tombstone/à jour) est recalculée à la lecture, pas stockée comme un flag figé.
 */
final class EntreeSnapshotDto
{
    /** @param list<string> $portesEligibles */
    public function __construct(
        public readonly string $identifiant,
        public readonly bool $revoque,
        public readonly int $versionMaj,
        public readonly ?string $nomPorteur = null,
        public readonly ?string $numeroBillet = null,
        public readonly ?string $typeSupport = null,
        public readonly ?string $typeDroit = null,
        public readonly ?int $compostagesRestants = null,
        public readonly ?string $validiteDebut = null,
        public readonly ?string $validiteFin = null,
        public readonly array $portesEligibles = [],
        public readonly ?string $sousReseauId = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'identifiant' => $this->identifiant,
            'revoque' => $this->revoque,
            'nomPorteur' => $this->nomPorteur,
            'numeroBillet' => $this->numeroBillet,
            'typeSupport' => $this->typeSupport,
            'typeDroit' => $this->typeDroit,
            'compostagesRestants' => $this->compostagesRestants,
            'validiteDebut' => $this->validiteDebut,
            'validiteFin' => $this->validiteFin,
            'portesEligibles' => $this->portesEligibles,
            'sousReseauId' => $this->sousReseauId,
            'versionMaj' => $this->versionMaj,
        ];
    }
}
