<?php

declare(strict_types=1);

use App\Application\QuotesService;
use App\Infrastructure\Config;
use App\Infrastructure\FinnhubService;

require __DIR__ . '/../App/vendor/autoload.php';
require_once __DIR__ . '/../App/Infrastructure/Env.php';
load_env();

$finnhubService = new FinnhubService(
    Config::getRequired('FINNHUB_API_KEY'),
    Config::get('X_FINNHUB_SECRET')
);

$quotesService = new QuotesService(
    $finnhubService,
    dirname(__DIR__) . '/storage/cache/quotes',
    (bool)Config::get('CRON_ACTIVO', false),
    max(60, (int)Config::get('CRON_INTERVALO', 60)),
    Config::get('CRON_HR_START', '09:00'),
    Config::get('CRON_HR_END', '18:00')
);

foreach ($quotesService->getSupportedCategories() as $category) {
    try {
        $quotesService->getQuotes($category);
        echo "Updated {$category}" . PHP_EOL;
    } catch (\Throwable $e) {
        echo "Failed {$category}: {$e->getMessage()}" . PHP_EOL;
    }
}
