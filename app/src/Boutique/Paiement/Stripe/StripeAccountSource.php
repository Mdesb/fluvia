<?php

declare(strict_types=1);

namespace App\Boutique\Paiement\Stripe;

/** D'où vient le compte Stripe d'un appel : la clé de l'exploitant, ou la clé globale de l'environnement (repli). */
enum StripeAccountSource: string
{
    case Profile = 'profile';
    case Global = 'global';
}
