<?php

declare(strict_types=1);

/*
 * Un AUTRE processus PHP qui appelle `GET /v1/me` N fois à travers le noyau complet, puis écrit le
 * décompte des statuts en JSON. Lancé par `PublicApiRateLimitTest` pour prouver que la limite est
 * comptée en commun par plusieurs processus — exactement ce que ferait un pool php-fpm.
 *
 * Usage : php call-v1-me.php <secret> <nombre>
 */

use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$app = dirname(__DIR__, 2);
require $app.'/vendor/autoload.php';
(new Dotenv())->bootEnv($app.'/.env');

$kernel = new Kernel('test', true);
$statuses = [];

for ($i = 0; $i < (int) $argv[2]; ++$i) {
    $request = Request::create('/v1/me', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer '.$argv[1]]);
    $response = $kernel->handle($request);
    $statuses[$response->getStatusCode()] = ($statuses[$response->getStatusCode()] ?? 0) + 1;
    $kernel->terminate($request, $response);
}

echo json_encode($statuses);
