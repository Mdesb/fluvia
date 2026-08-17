<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use Symfony\Component\Validator\Constraint;

/** Valide la clé de contrôle d'un code-barres EAN-13 ou EAN-8 (RG-STOCK-02, CA-1). */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
final class CodeEanValide extends Constraint
{
    public string $message = 'Le code-barres « {{ value }} » n\'est pas un EAN-13/EAN-8 valide (clé de contrôle incorrecte).';
}
