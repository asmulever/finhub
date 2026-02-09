<?php
ini_set('open_basedir', '');
require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/ApplicationBootstrap.php';

use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Application\Analytics\PredictionMarketService;

$bootstrap = new ApplicationBootstrap(dirname(__DIR__));
$container = $bootstrap->createContainer();
/** @var PredictionMarketService $service */
$service = $container->get('prediction_market_service');
$result = $service->getTrending();
print_r([
    'as_of' => $result['as_of'] ?? null,
    'items' => array_slice($result['items'] ?? [], 0, 2),
    'cache' => $result['cache'] ?? null,
]);
