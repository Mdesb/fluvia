<?php

declare(strict_types=1);

namespace App\Support\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Support\Service\ImporteurAideService;
use App\Vente\Service\LecteurCorps;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * POST /support/import/executer (§3 plan-support.md) : déclenchement manuel du même service que la
 * commande `support:importer-aide` — pour un admin sans accès console. Corps optionnel :
 * { "chemin"?: string, "dryRun"?: bool }.
 *
 * @implements ProcessorInterface<mixed, JsonResponse>
 */
final class ImportExecuterProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ImporteurAideService $importeur,
        private readonly LecteurCorps $lecteur,
        private readonly string $cheminAideParDefaut,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        $corps = $this->lecteur->corps();
        $chemin = \is_string($corps['chemin'] ?? null) && $corps['chemin'] !== '' ? $corps['chemin'] : $this->cheminAideParDefaut;
        $dryRun = (bool) ($corps['dryRun'] ?? false);

        $resume = $this->importeur->executer($chemin, $dryRun);

        return new JsonResponse([
            'dryRun' => $dryRun,
            'total' => $resume->total(),
            'cree' => $resume->cree,
            'maj' => $resume->maj,
            'inchange' => $resume->inchange,
            'erreur' => $resume->erreur,
            'lignes' => $resume->lignes,
        ]);
    }
}
