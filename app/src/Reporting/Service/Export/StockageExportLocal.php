<?php

declare(strict_types=1);

namespace App\Reporting\Service\Export;

use App\Reporting\Service\StockageExportInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Adaptateur filesystem de `StockageExportInterface` (§2.8 plan-reporting.md), sous
 * `%kernel.project_dir%/var/reporting/exports/`.
 */
final class StockageExportLocal implements StockageExportInterface
{
    public function __construct(
        #[Autowire('%kernel.project_dir%')] private readonly string $projectDir,
    ) {
    }

    public function stocker(string $contenu, string $extension): string
    {
        $dossier = $this->dossier();
        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
        }
        $nom = bin2hex(random_bytes(16)) . '.' . $extension;
        file_put_contents($dossier . '/' . $nom, $contenu);

        return $nom;
    }

    public function recuperer(string $reference): string
    {
        $chemin = $this->dossier() . '/' . basename($reference);
        if (!is_file($chemin)) {
            throw new \RuntimeException(sprintf('Fichier d\'export introuvable : %s.', $reference));
        }
        $contenu = file_get_contents($chemin);

        return $contenu !== false ? $contenu : '';
    }

    private function dossier(): string
    {
        return $this->projectDir . '/var/reporting/exports';
    }
}
