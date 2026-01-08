<?php
declare(strict_types=1);

use FinHub\Application\Auth\AuthService;
use FinHub\Infrastructure\ApiDispatcher;
use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\User\PdoUserRepository;

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
$priceService = $container->get('price_service');
$eodhdClient = $container->get('eodhd_client');
$providerUsage = $container->get('provider_usage');
$portfolioService = $container->get('portfolio_service');
$portfolioSummaryService = $container->get('portfolio_summary_service');
$portfolioSectorService = $container->get('portfolio_sector_service');
$portfolioHeatmapService = $container->get('portfolio_heatmap_service');
$dataLakeService = $container->get('datalake_service');
$instrumentCatalogService = $container->get('instrument_catalog_service');
$polygonService = $container->get('polygon_service');
$tiingoService = $container->get('tiingo_service');
$stooqService = $container->get('stooq_service');
$ravaCedearsService = $container->get('rava_cedears_service');
$ravaAccionesService = $container->get('rava_acciones_service');
$ravaBonosService = $container->get('rava_bonos_service');
$ravaHistoricosService = $container->get('rava_historicos_service');

$traceId = generateTraceId();
set_error_handler(fn ($severity, $message, $file, $line) => handleFatalError($logger, $traceId, $message, $file, $line));

$userRepository = new PdoUserRepository($pdo);
$authService = new AuthService($userRepository, $passwordHasher, $jwt, $config);
$dispatcher = new ApiDispatcher(
    $config,
    $logger,
    $authService,
    $priceService,
    $userRepository,
    $jwt,
    $passwordHasher,
    $eodhdClient,
    $providerUsage,
    $portfolioService,
    $portfolioSummaryService,
    $portfolioSectorService,
    $portfolioHeatmapService,
    $dataLakeService,
    $instrumentCatalogService,
    $polygonService,
    $tiingoService,
    $stooqService,
    $ravaCedearsService,
    $ravaAccionesService,
    $ravaBonosService,
    $ravaHistoricosService
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
