<?php

declare(strict_types=1);

namespace App\Reporting\Service;

/**
 * Port de stockage d'un fichier d'export généré (§2.8 plan-reporting.md) : référence opaque en
 * retour, isolé pour permettre un futur adaptateur S3-compatible sans changer l'appelant.
 */
interface StockageExportInterface
{
    public function stocker(string $contenu, string $extension): string;

    public function recuperer(string $reference): string;
}
