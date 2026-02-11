<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/ApplicationBootstrap.php';

use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Application\R2Lite\ProviderInterface;

$bootstrap = new ApplicationBootstrap();
$container = $bootstrap->createContainer();

/** @var ProviderInterface[] $providers */
$providers = [
    $container->get('r2lite_service') ? null : null, // placeholder to show how to fetch providers individually
];

echo "Ejemplo: prueba TwelveData/AlphaVantage/Stooq\n";
// Nota: aquí se demuestra TwelveData directamente:
$config = $container->get('config');
$logger = $container->get('logger');
$cache = $container->get('cache');
$twelve = new \FinHub\Infrastructure\R2Lite\Provider\TwelveDataProvider($config, $logger, $cache);
$rava = new \FinHub\Infrastructure\R2Lite\Provider\RavaProvider($config, $logger, $cache);

$from = new DateTimeImmutable('-10 days');
$to = new DateTimeImmutable('today');

try {
    $rows = $twelve->fetchDaily('AAPL', $from, $to, 'MERCADO_GLOBAL');
    echo "TwelveData rows: " . count($rows) . PHP_EOL;
} catch (Throwable $e) {
    echo "Error TwelveData: " . $e->getMessage() . PHP_EOL;
}

echo "Rava snapshot puntual (sin histórico)" . PHP_EOL;
$tests = [
    ['symbol' => 'GGAL', 'cat' => 'ACCIONES_AR'],
    ['symbol' => 'AL30', 'cat' => 'BONO'],
];
foreach ($tests as $t) {
    try {
        $rows = $rava->fetchDaily($t['symbol'], $from, $to, $t['cat']);
        $close = $rows[0]['close'] ?? null;
        echo sprintf("Rava %s (%s): %d close=%s\n", $t['symbol'], $t['cat'], count($rows), $close ?? 'n/a');
    } catch (Throwable $e) {
        echo sprintf("Rava %s (%s) error: %s\n", $t['symbol'], $t['cat'], $e->getMessage());
    }
}
