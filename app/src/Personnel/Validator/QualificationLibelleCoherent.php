<?php

declare(strict_types=1);

namespace App\Personnel\Validator;

use Symfony\Component\Validator\Constraint;

/** `Qualification.libelle` requis si `type = autre` (RG-PERSO-02). */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class QualificationLibelleCoherent extends Constraint
{
    public string $message = 'Le libellé est requis lorsque le type de qualification est « autre » (RG-PERSO-02).';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
