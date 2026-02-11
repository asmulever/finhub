<?php
declare(strict_types=1);

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/ApplicationBootstrap.php';

use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Infrastructure\R2Lite\Provider\AlphaVantageProvider;

$bootstrap = new ApplicationBootstrap();
$container = $bootstrap->createContainer();

$config = $container->get('config');
$logger = $container->get('logger');
$cache = $container->get('cache');

$apiKey = (string) $config->get('ALPHAVANTAGE_API_KEY', '');
if ($apiKey === '') {
    fwrite(STDERR, "ALPHAVANTAGE_API_KEY no definido en .env\n");
    exit(1);
}

$provider = new AlphaVantageProvider($config, $logger, $cache);
$from = new DateTimeImmutable('-5 days');
$to = new DateTimeImmutable('today');
$symbol = 'MSFT';

try {
    $rows = $provider->fetchDaily($symbol, $from, $to, 'MERCADO_GLOBAL');
    $count = count($rows);
    $first = $count ? $rows[0] : null;
    $last = $count ? $rows[$count - 1] : null;
    echo "AlphaVantage {$symbol} rows: {$count}\n";
    if ($first) {
        echo "First: {$first['as_of']} close={$first['close']}\n";
    }
    if ($last && $count > 1) {
        echo "Last: {$last['as_of']} close={$last['close']}\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, "Error AlphaVantage: " . $e->getMessage() . "\n");
    exit(1);
}
