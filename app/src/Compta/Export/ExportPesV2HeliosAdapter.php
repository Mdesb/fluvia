<?php

declare(strict_types=1);

namespace App\Compta\Export;

use App\Compta\Entity\ExportComptable;
use App\Compta\Enum\FormatExport;
use App\Compta\Port\ExportComptableInterface;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * Export PES V2/Hélios — **squelette** (structure XML minimale documentée « à compléter avec le
 * protocole Trésor exact »). Point EXPERT #6 (§8 du plan) : `genereTitreRegularisation` prépare le
 * bloc de régularisation, non détaillé tant que l'arbitrage comptable public n'est pas rendu.
 */
#[AutoconfigureTag('compta.export_adapter')]
final class ExportPesV2HeliosAdapter implements ExportComptableInterface
{
    public function format(): FormatExport
    {
        return FormatExport::PesV2Helios;
    }

    public function generer(ExportComptable $export, iterable $ecrituresValidees): string
    {
        $nb = 0;
        $total = 0;
        foreach ($ecrituresValidees as $ecriture) {
            ++$nb;
            $total += $ecriture->totalDebitCentimes();
        }

        $titre = $export->isGenereTitreRegularisation()
            ? '<TitreRegularisation present="true" />'
            : '<!-- Titre de régularisation non activé (point EXPERT #6, arbitrage comptable public en attente) -->';

        return sprintf(
            "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<PES_V2>\n  <Entete siren=\"%s\" />\n  <Bloc nbEcritures=\"%d\" totalCentimes=\"%d\" />\n  %s\n  <!-- Structure minimale : protocole Trésor exact non cadré dans les sources (point EXPERT). -->\n</PES_V2>",
            $export->getProfilExploitant()?->getSiren() ?? '',
            $nb,
            $total,
            $titre,
        );
    }
}
