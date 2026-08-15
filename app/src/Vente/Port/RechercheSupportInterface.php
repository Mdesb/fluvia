<?php

declare(strict_types=1);

namespace App\Vente\Port;

use Symfony\Component\Uid\Uuid;

/**
 * Frontière M4 (CRM, hors périmètre L2) : résout un client à partir d'un n° de support/carte émis
 * par M2 (`BilletSupport.identifiantSupport`), pour la recherche clients CA-1 (spec-crm.md §6).
 */
interface RechercheSupportInterface
{
    public function clientPourSupport(string $identifiant): ?Uuid;
}
