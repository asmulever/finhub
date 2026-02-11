<?php
declare(strict_types=1);

use FinHub\Application\Auth\AuthService;
use FinHub\Infrastructure\ApiDispatcher;
use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\User\UserDeletionService;

require_once __DIR__ . '/autoload.php';
require_once __DIR__ . '/config/ApplicationBootstrap.php';
$bootstrap = new ApplicationBootstrap();
$container = $bootstrap->createContainer();

/** @var Config $config */
$config = $container->get('config');
/** @var LoggerInterface $logger */
$logger = $container->get('logger');
$pdo = $container->get('pdo');
$jwt = $container->get('jwt');
$passwordHasher = $container->get('password_hasher');
$portfolioService = $container->get('portfolio_service');
$portfolioSummaryService = $container->get('portfolio_summary_service');
$portfolioHeatmapService = $container->get('portfolio_heatmap_service');
$predictionService = $container->get('prediction_service');
$predictionMarketService = $container->get('prediction_market_service');
$cache = $container->get('cache');
$dataLakeService = $container->get('datalake_service');
$r2liteService = $container->get('r2lite_service');
$openRouterClient = $container->get('openrouter_client');
$instrumentCatalogService = $container->get('instrument_catalog_service');
$ravaViewsService = $container->get('rava_views_service');
$userRepository = $container->get('user_repository');
$userDeletionService = $container->get('user_deletion_service');
$activationService = $container->get('activation_service');
$signalService = $container->get('signal_service');
$backtestService = $container->get('backtest_service');
$dataReadinessService = $container->get('data_readiness_service');

$traceId = generateTraceId();
set_error_handler(fn ($severity, $message, $file, $line) => handleFatalError($logger, $traceId, $message, $file, $line));

$authService = new AuthService($userRepository, $passwordHasher, $jwt, $config);
$dispatcher = new ApiDispatcher(
    $config,
    $logger,
    $authService,
    $activationService,
    $userRepository,
    $jwt,
    $passwordHasher,
    $portfolioService,
    $portfolioSummaryService,
    $portfolioHeatmapService,
    $predictionService,
    $predictionMarketService,
    $openRouterClient,
    $dataLakeService,
    $instrumentCatalogService,
    $ravaViewsService,
    $signalService,
    $dataReadinessService,
    $userDeletionService,
    $backtestService,
    $cache
);
$dispatcher->dispatch($traceId);

function generateTraceId(): string
{
    return bin2hex(random_bytes(16));
}

function handleFatalError(LoggerInterface $logger, string $traceId, string $message, string $file, int $line): bool
{
    $logger->error('php.error', ['trace_id' => $traceId, 'message' => $message, 'file' => $file, 'line' => $line]);
    return true; // evitar que PHP vuelque el warning en la salida y rompa headers
}
