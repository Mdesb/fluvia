<?php

declare(strict_types=1);

namespace App\Compta\Port;

final class PayFipInitiationResultat
{
    public function __construct(
        public readonly string $referenceTransaction,
        public readonly string $urlRedirection,
    ) {
    }
}
