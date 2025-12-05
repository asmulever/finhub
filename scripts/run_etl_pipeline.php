#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Application\Etl\GetEtlStatusUseCase;
use App\Application\Etl\RunEtlJobUseCase;
use App\Infrastructure\ApplicationContainerFactory;

require __DIR__ . '/../App/vendor/autoload.php';
load_env();

$container = ApplicationContainerFactory::create();
$runUseCase = $container->get(RunEtlJobUseCase::class);
$statusUseCase = $container->get(GetEtlStatusUseCase::class);

$options = getopt('', [
    'source::',
    'from::',
    'to::',
    'days::',
    'staging-retention-days::',
    'history-days::',
    'instrument-limit::',
    'target-date::',
    'help',
]);

if (isset($options['help'])) {
    echo "Usage: php run_etl_pipeline.php [--source=FINNHUB|RAVA] [--from=YYYY-MM-DD] [--to=YYYY-MM-DD]\n";
    echo "       [--days=N] [--staging-retention-days=N] [--history-days=N] [--instrument-limit=N] [--target-date=YYYY-MM-DD]\n";
    exit(0);
}

function option(?string $value, $fallback)
{
    if ($value === null || $value === '') {
        return $fallback;
    }
    return is_numeric($fallback) ? (int)$value : $value;
}

$ingestParams = [
    'source' => strtoupper(option($options['source'] ?? null, 'FINNHUB')),
    'from_date' => option($options['from'] ?? null, null),
    'to_date' => option($options['to'] ?? null, null),
    'days' => option($options['days'] ?? null, 7),
];

$normalizeParams = [
    'from_date' => option($options['from'] ?? null, null),
    'to_date' => option($options['to'] ?? null, null),
    'days' => option($options['days'] ?? null, 7),
    'staging_retention_days' => option($options['staging-retention-days'] ?? null, 60),
];

$indicatorsParams = [
    'days' => option($options['days'] ?? null, 60),
    'history_days' => option($options['history-days'] ?? null, 260),
    'instrument_limit' => option($options['instrument-limit'] ?? null, 100),
];

$signalsParams = [
    'target_date' => option($options['target-date'] ?? null, null),
    'instrument_limit' => option($options['instrument-limit'] ?? null, 100),
];

function displayResult(string $label, array $payload): void
{
    echo "=== {$label} ===\n";
    foreach ($payload as $key => $value) {
        if (is_array($value)) {
            echo "{$key}: " . json_encode($value) . "\n";
            continue;
        }
        echo "{$key}: {$value}\n";
    }
    echo "\n";
}

displayResult('Starting config', $ingestParams);

$results = [];
try {
    $results['ingest'] = $runUseCase->ingest($ingestParams);
    displayResult('Ingest result', $results['ingest']);

    $results['normalize'] = $runUseCase->normalizePrices($normalizeParams);
    displayResult('Normalize result', $results['normalize']);

    $results['indicators'] = $runUseCase->calcIndicators($indicatorsParams);
    displayResult('Indicators result', $results['indicators']);

    $results['signals'] = $runUseCase->calcSignals($signalsParams);
    displayResult('Signals result', $results['signals']);
} catch (\Throwable $exception) {
    fwrite(STDERR, "ETL pipeline failed: " . $exception->getMessage() . "\n");
    exit(1);
}

$status = $statusUseCase->execute();
displayResult('ETL status snapshot', ['jobs' => $status]);

echo "ETL pipeline completed.\n";
