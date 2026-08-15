<?php

declare(strict_types=1);

namespace App\Compta\Port;

use App\Compta\Entity\ProfilExploitant;
use App\Compta\Regime\Dto\AvoirProjectionDto;
use App\Compta\Regime\Dto\VenteProjectionDto;

/**
 * Frontière M2 (lecture seule, §4 du plan) : source des écritures (RG-COMPTA-04). M6 ne modifie
 * jamais `App\Vente\Entity\Vente`/`Avoir`.
 */
interface ProjectionVenteInterface
{
    /** @return iterable<VenteProjectionDto> ventes validées du périmètre du profil, non encore comptabilisées */
    public function ventesValideesNonComptabilisees(ProfilExploitant $profil): iterable;

    /** @return iterable<AvoirProjectionDto> avoirs du périmètre du profil, non encore extournés */
    public function avoirsNonComptabilises(ProfilExploitant $profil): iterable;
}
