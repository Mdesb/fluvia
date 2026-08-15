<?php

declare(strict_types=1);

namespace App\Vente\Port;

/** Stub L2 : aucun historique. */
final class HistoriqueVenteStub implements HistoriqueVenteInterface
{
    public function pourClients(array $clientIds): iterable
    {
        return [];
    }
}
