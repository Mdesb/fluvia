<?php

declare(strict_types=1);

namespace App\Platform\Nf525\Alert;

/** Résultat de `Nf525AlertStore::open` : la référence à donner au caissier, et si l'alerte vient de s'ouvrir. */
final class OpenedAlert
{
    public function __construct(
        public readonly string $reference,
        public readonly bool $opened,
        public readonly string $alertId,
    ) {
    }
}
