<?php

declare(strict_types=1);

namespace App\Compta\Enum;

/** Mode d'une clé Stripe, calculé sur son préfixe à la saisie (`sk_test_`/`rk_test_` ou `sk_live_`/`rk_live_`). */
enum StripeKeyMode: string
{
    case Test = 'test';
    case Live = 'live';

    /** @return self|null null si le préfixe n'est pas celui d'une clé secrète ou restreinte Stripe */
    public static function fromKey(#[\SensitiveParameter] string $key): ?self
    {
        return match (true) {
            str_starts_with($key, 'sk_test_'), str_starts_with($key, 'rk_test_') => self::Test,
            str_starts_with($key, 'sk_live_'), str_starts_with($key, 'rk_live_') => self::Live,
            default => null,
        };
    }
}
