<?php

declare(strict_types=1);

namespace App\Stock\Validator;

use Symfony\Component\Validator\Constraint;

/** RG-STOCK-03/CA-3 : un seul `CatalogueFournisseur.principal=true` par `articleStock` (garde applicative). */
#[\Attribute(\Attribute::TARGET_CLASS)]
final class PrincipalUnique extends Constraint
{
    public string $message = 'Un seul fournisseur principal est autorisé par article de stock.';

    public function getTargets(): string|array
    {
        return self::CLASS_CONSTRAINT;
    }
}
