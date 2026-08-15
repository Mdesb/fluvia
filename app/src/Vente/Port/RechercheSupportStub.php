<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/** Stub L2 : aucun support connu. */
final class RechercheSupportStub implements RechercheSupportInterface
{
    public function clientPourSupport(string $identifiant): ?Uuid
    {
        return null;
    }
}
