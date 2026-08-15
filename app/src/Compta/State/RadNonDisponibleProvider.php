<?php

declare(strict_types=1);

namespace App\Compta\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * RAD/redevances DSP — point d'extension **non implémenté** dans ce lot (§7.6 du plan, CA-15
 * documentation du hors-périmètre) : aucune US-L4 du backlog actuel ne couvre le RAD (spec §4.8).
 * N'expose jamais le journal comptable détaillé (cf. spec §3, droits Autorité délégante).
 *
 * @implements ProviderInterface<JsonResponse>
 */
final class RadNonDisponibleProvider implements ProviderInterface
{
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): JsonResponse
    {
        return new JsonResponse([
            'disponible' => false,
            'message' => 'RAD/redevances DSP : non disponible dans ce lot (hors périmètre L4, aucune US-L4 dédiée — spec §4.8).',
        ], JsonResponse::HTTP_OK);
    }
}
