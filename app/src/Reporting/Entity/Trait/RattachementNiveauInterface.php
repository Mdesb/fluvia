<?php

declare(strict_types=1);

namespace App\Reporting\Entity\Trait;

use App\Organisation\Entity\Etablissement;
use App\Organisation\Entity\Groupe;
use App\Organisation\Entity\Region;
use App\Reporting\Enum\NiveauEntite;

/**
 * Contrat implémenté par toute entité Reporting portant `RattachementNiveauTrait` (§1.1
 * plan-reporting.md) — consommé par `PerimetreReportingExtension` sans dépendre d'un type concret.
 */
interface RattachementNiveauInterface
{
    public function getNiveau(): NiveauEntite;

    public function getEtablissement(): ?Etablissement;

    public function getRegion(): ?Region;

    public function getGroupe(): ?Groupe;
}
