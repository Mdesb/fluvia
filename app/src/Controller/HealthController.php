<?php

declare(strict_types=1);

namespace App\Controller;

use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Sonde de santé du socle : disponibilité de l'application et de la base de données.
 * Sert de vérification de bout en bout de l'environnement (US-L0-01).
 */
#[AsController]
final class HealthController
{
    #[Route('/health', name: 'health', methods: ['GET'])]
    public function __invoke(Connection $connection): JsonResponse
    {
        $database = 'up';
        try {
            $connection->executeQuery('SELECT 1');
        } catch (\Throwable) {
            $database = 'down';
        }

        return new JsonResponse([
            'status' => $database === 'up' ? 'ok' : 'degraded',
            'service' => 'billetterie-api',
            'checks' => [
                'app' => 'up',
                'database' => $database,
            ],
        ], $database === 'up' ? 200 : 503);
    }
}
