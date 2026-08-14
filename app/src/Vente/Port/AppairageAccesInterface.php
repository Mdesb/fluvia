<?php

declare(strict_types=1);

namespace App\Vente\Port;

use App\Vente\Entity\BilletSupport;

/**
 * Frontière module Accès (L3, hors périmètre L2) : appairage physique du support aux droits vendus,
 * activation, et dévalidation lors d'une annulation après impression (US-L2-08/09). En L2, un stub
 * simule l'appairage ; un échec bloque la remise du support (CA-12).
 */
interface AppairageAccesInterface
{
    /** Tente d'appairer le support à ses droits ; renvoie true si actif, false en cas d'échec. */
    public function appairer(BilletSupport $support): bool;

    /** Dévalide le support émis côté Accès (annulation après impression, US-L2-09). */
    public function invalider(BilletSupport $support): void;
}
