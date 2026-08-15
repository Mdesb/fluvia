<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Enum\FormatExport;
use App\Compta\Port\ExportComptableInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/** Export CIEL — squelette (format éditeur à finaliser), cf. `CsvEditeurSkeletonTrait`. */
#[AutoconfigureTag('compta.export_adapter')]
final class ExportCielAdapter implements ExportComptableInterface
{
    use CsvEditeurSkeletonTrait;

    public function format(): FormatExport
    {
        return FormatExport::Ciel;
    }
}
