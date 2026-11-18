<?php

declare(strict_types=1);

namespace App\Platform\Nf525;

/** Les trois familles de chaînes scellées NF525 (vente, comptabilité, facturation). */
enum SealChainType: string
{
    case Sales = 'sales';
    case Accounting = 'accounting';
    case Invoicing = 'invoicing';
}
