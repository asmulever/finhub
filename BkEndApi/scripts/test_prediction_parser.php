<?php
ini_set('open_basedir', '');
require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/ApplicationBootstrap.php';

use FinHub\Infrastructure\Analytics\HttpYahooPredictionFetcher;
use FinHub\Infrastructure\Logging\FileLogger;
use FinHub\Infrastructure\Config\Config;

$logger = new FileLogger(__DIR__ . '/../storage/logs', 'debug');
$fetcher = new HttpYahooPredictionFetcher($logger);
$html = file_get_contents(__DIR__ . '/../storage/fixtures/prediction_trending_sample.html');
$items = $fetcher->parseHtml($html);
assert(is_array($items) && count($items) === 2, 'Debe parsear 2 items');
assert($items[0]['id'] === 'market-foo', 'ID esperado market-foo');
assert(count($items[0]['outcomes']) === 2, '2 outcomes en market-foo');
assert(abs($items[0]['outcomes'][0]['probability'] - 0.12) < 0.0001, 'probabilidad 0.12');
assert($items[1]['id'] === 'market-bar', 'ID market-bar');
assert(count($items[1]['outcomes']) === 1, '1 outcome en market-bar');
assert(abs($items[1]['outcomes'][0]['probability'] - 0.33) < 0.0001, 'probabilidad 0.33');
echo "ok\n";
