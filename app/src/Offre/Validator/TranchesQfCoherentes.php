<?php

declare(strict_types=1);

namespace App\Offre\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Les tranches de QF d'un même type de tarif doivent être contiguës, sans trou ni chevauchement
 * (CA-6), afin qu'une valeur de QF donnée résolve toujours vers une et une seule tranche.
 */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class TranchesQfCoherentes extends Constraint
{
    public string $chevauchementMessage = 'Cette tranche en chevauche une autre sur le même type de tarif.';
    public string $trouMessage = 'Cette tranche laisse un trou avec la tranche précédente (les bornes doivent être contiguës).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
