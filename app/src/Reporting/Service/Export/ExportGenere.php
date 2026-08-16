<?php

declare(strict_types=1);

namespace App\Reporting\Service\Export;

/** Résultat d'une génération d'export (§2.8 plan-reporting.md) : octets + extension de fichier. */
final readonly class ExportGenere
{
    public function __construct(
        public string $contenu,
        public string $extension,
    ) {
    }
}
