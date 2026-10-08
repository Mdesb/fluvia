<?php

declare(strict_types=1);

/*
 * Un AUTRE processus PHP qui encaisse un règlement à travers le noyau complet — ce que ferait une
 * seconde requête php-fpm. Il pose `SETTLEMENT_BARRIER` : au terminal ou au débit du porte-monnaie,
 * il s'arrête jusqu'à ce que le test le libère (`SettlementBarrier`). Il écrit le statut et le corps
 * de sa réponse dans `<barrière>/response.json`.
 *
 * Usage : php settle-in-other-process.php <barrière> <vente> <jeton> <établissement> <corps JSON> [issue TPE]
 *
 * À la place de la vente, un chemin (« /api/… ») envoie le corps à une autre écriture : la facturation
 * d'un no-show, qui débite le porte-monnaie (lot 4).
 */

use App\Kernel;
use App\Tests\Vente\Support\SettlementBarrier;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\HttpFoundation\Request;

$app = dirname(__DIR__, 3);
require $app . '/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($app . '/.env');
putenv(SettlementBarrier::ENV . '=' . $argv[1]);

$server = [
    'HTTP_AUTHORIZATION' => 'Bearer ' . $argv[3],
    'HTTP_X_ETABLISSEMENT' => $argv[4],
    'CONTENT_TYPE' => 'application/json',
    'HTTP_ACCEPT' => 'application/ld+json',
];
if (($argv[6] ?? '') !== '') {
    $server['HTTP_X_TPE_SIMULE'] = $argv[6];
}

$kernel = new Kernel('test', true);
$chemin = str_starts_with($argv[2], '/') ? $argv[2] : '/api/ventes/' . $argv[2] . '/paiements';
$request = Request::create($chemin, 'POST', server: $server, content: $argv[5]);
$response = $kernel->handle($request);
file_put_contents($argv[1] . '/response.json', json_encode(['status' => $response->getStatusCode(), 'body' => $response->getContent()]));
$kernel->terminate($request, $response);
