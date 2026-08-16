<?php

declare(strict_types=1);

namespace App\Reporting\Exception;

/**
 * Levée par un générateur d'export « port/stub » (PDF, XLSX — §2.8 plan-reporting.md) : ces
 * formats ne sont pas implémentés en MVP. Interceptée par `ExecuterRapportsCommand`/
 * `ExportManuelProcessor` qui marquent `Export.statut = echec` avec un message explicite plutôt que
 * de planter silencieusement (Risque §9.9 plan-reporting.md).
 */
final class GenerationExportNonSupporteeException extends \RuntimeException
{
}
