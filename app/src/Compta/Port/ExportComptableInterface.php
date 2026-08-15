<?php

declare(strict_types=1);

namespace App\Compta\Port;

use App\Compta\Entity\EcritureComptable;
use App\Compta\Entity\ExportComptable;
use App\Compta\Enum\FormatExport;

/**
 * Port des exports comptables commutés par profil (RG-EXPORT-07, §5 du plan). Chaque implémentation
 * est taguée `compta.export_adapter`, sélectionnée par `ExportComptableResolver` (aucun `switch`).
 */
interface ExportComptableInterface
{
    public function format(): FormatExport;

    /**
     * @param iterable<EcritureComptable> $ecrituresValidees
     *
     * @return string contenu du fichier généré, prêt à écrire/télécharger
     */
    public function generer(ExportComptable $export, iterable $ecrituresValidees): string;
}
