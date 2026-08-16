<?php

declare(strict_types=1);

namespace App\Reporting\Security;

use Symfony\Component\Uid\Uuid;

/**
 * Récapitulatif du périmètre Reporting effectif d'un utilisateur (§2.2 plan-reporting.md) :
 * établissements, régions ENTIÈREMENT couvertes, groupe(s) ENTIÈREMENT couverts.
 */
final readonly class PerimetreReporting
{
    /**
     * @param list<Uuid> $etablissements
     * @param list<Uuid> $regions
     * @param list<Uuid> $groupes
     */
    public function __construct(
        public array $etablissements,
        public array $regions,
        public array $groupes,
    ) {
    }

    public function estAutoriseEtablissement(Uuid $id): bool
    {
        return $this->contient($this->etablissements, $id);
    }

    public function estAutoriseRegion(Uuid $id): bool
    {
        return $this->contient($this->regions, $id);
    }

    public function estAutoriseGroupe(Uuid $id): bool
    {
        return $this->contient($this->groupes, $id);
    }

    /** @param list<Uuid> $liste */
    private function contient(array $liste, Uuid $id): bool
    {
        foreach ($liste as $item) {
            if ($item->equals($id)) {
                return true;
            }
        }

        return false;
    }
}
