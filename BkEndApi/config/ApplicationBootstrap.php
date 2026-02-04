<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Config;

use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Config\Container;
use FinHub\Infrastructure\Logging\FileLogger;
use FinHub\Application\Backtest\BacktestService;
use FinHub\Application\MarketData\PriceService;
use FinHub\Application\MarketData\ProviderUsageService;
use FinHub\Application\Auth\ActivationService;
use FinHub\Application\MarketData\RavaBonosService;
use FinHub\Application\MarketData\RavaAccionesService;
use FinHub\Application\MarketData\RavaCedearsService;
use FinHub\Application\MarketData\RavaHistoricosService;
use FinHub\Application\MarketData\PolygonService;
use FinHub\Application\MarketData\TiingoService;
use FinHub\Application\MarketData\StooqService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Application\Portfolio\PortfolioSummaryService;
use FinHub\Application\Portfolio\PortfolioSectorService;
use FinHub\Application\Portfolio\PortfolioHeatmapService;
use FinHub\Application\Signals\SignalService;
use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\DataLake\InstrumentCatalogService;
use FinHub\Application\Analytics\PredictionService;
use FinHub\Infrastructure\MarketData\AlphaVantageClient;
use FinHub\Infrastructure\MarketData\TwelveDataClient;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\MarketData\Provider\AlphaVantageProvider;
use FinHub\Infrastructure\MarketData\Provider\EodhdProvider;
use FinHub\Infrastructure\MarketData\Provider\TwelveDataProvider;
use FinHub\Infrastructure\MarketData\PolygonClient;
use FinHub\Infrastructure\MarketData\TiingoClient;
use FinHub\Infrastructure\MarketData\StooqClient;
use FinHub\Infrastructure\MarketData\RavaBonosClient;
use FinHub\Infrastructure\MarketData\RavaAccionesClient;
use FinHub\Infrastructure\MarketData\RavaCedearsClient;
use FinHub\Infrastructure\MarketData\RavaCedearsCache;
use FinHub\Infrastructure\MarketData\RavaHistoricosClient;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;
use FinHub\Infrastructure\Portfolio\PdoPortfolioRepository;
use FinHub\Infrastructure\DataLake\PdoPriceSnapshotRepository;
use FinHub\Infrastructure\DataLake\PdoInstrumentCatalogRepository;
use FinHub\Infrastructure\User\PdoUserRepository;
use FinHub\Infrastructure\User\UserDeletionService;
use FinHub\Infrastructure\Mail\BrevoMailSender;
use FinHub\Infrastructure\Analytics\PdoPredictionRepository;
use FinHub\Infrastructure\Analytics\PdoPredictionRunRepository;
use FinHub\Infrastructure\Signals\PdoSignalRepository;
use FinHub\Infrastructure\Backtest\PdoBacktestRepository;

final class ApplicationBootstrap
{
    private string $rootDir;
    private array $env;

    /**
     * Configura el bootstrap de la aplicación tomando como raíz el directorio del proyecto.
     */
    public function __construct(?string $rootDir = null)
    {
        $this->rootDir = $rootDir ?? (realpath(__DIR__ . '/../..') ?: dirname(__DIR__, 2));
        $this->env = $this->loadEnvironment();
    }

    /**
     * Devuelve un contenedor configurado con la base de datos, logging y seguridad listos para usar.
     */
    public function createContainer(): Container
    {
        $config = new Config($this->env);
        date_default_timezone_set($config->get('APP_TIMEZONE', 'UTC'));

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config->require('DB_HOST'),
            $config->get('DB_PORT', 3306),
            $config->require('DB_DATABASE')
        );

        $pdo = new \PDO(
            $dsn,
            $config->require('DB_USERNAME'),
            $config->require('DB_PASSWORD'),
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $logPath = $this->normalizeLogPath($config->get('LOG_FILE_PATH'));
        $logger = new FileLogger($logPath, $config->get('LOG_LEVEL', 'info'));
        $jwt = new JwtTokenProvider($config->require('JWT_SECRET'));
        $passwordHasher = new PasswordHasher();
        $userRepository = new PdoUserRepository($pdo);
        $userDeletionService = new UserDeletionService($pdo);
        $predictionRunRepository = new PdoPredictionRunRepository($pdo);
        $predictionRepository = new PdoPredictionRepository($pdo);
        $mailSender = new BrevoMailSender($config);
        $activationService = new ActivationService($userRepository, $passwordHasher, $jwt, $config, $mailSender);
        $apiKey = trim((string) $config->get('TWELVE_DATA_API_KEY', ''));
        $twelveDataClient = null;
        if ($apiKey !== '') {
            $twelveDataClient = new TwelveDataClient(
                $apiKey,
                $config->get('TWELVE_DATA_BASE_URL', 'https://api.twelvedata.com'),
                (int) $config->get('TWELVE_DATA_TIMEOUT_SECONDS', 5)
            );
        }
        $eodhdClient = new EodhdClient($config);
        $alphaClient = new AlphaVantageClient($config);
        $ravaClient = new RavaCedearsClient($config);
        $ravaCache = new RavaCedearsCache($this->rootDir . '/storage/rava_cache', 'cedears.json');
        $ravaCedearsService = new RavaCedearsService($ravaClient, $ravaCache, $logger);
        $ravaAccionesClient = new RavaAccionesClient($config);
        $ravaAccionesCache = new RavaCedearsCache($this->rootDir . '/storage/rava_cache', 'acciones.json');
        $ravaAccionesService = new RavaAccionesService($ravaAccionesClient, $ravaAccionesCache, $logger);
        $ravaBonosClient = new RavaBonosClient($config);
        $ravaBonosCache = new RavaCedearsCache($this->rootDir . '/storage/rava_cache', 'bonos.json');
        $ravaBonosService = new RavaBonosService($ravaBonosClient, $ravaBonosCache, $logger);
        $ravaHistoricosClient = new RavaHistoricosClient($config);
        $ravaHistoricosService = new RavaHistoricosService($ravaHistoricosClient, $logger);
        $polygonClient = null;
        $polygonApiKey = trim((string) $config->get('POLYGON_API_KEY', ''));
        if ($polygonApiKey !== '') {
            $polygonClient = new PolygonClient($config);
        }
        $tiingoClient = null;
        $tiingoToken = trim((string) $config->get('TIINGO_API_TOKEN', ''));
        if ($tiingoToken !== '') {
            $tiingoClient = new TiingoClient($config);
        }
        $stooqClient = new StooqClient($config);
        $metrics = new \FinHub\Infrastructure\MarketData\ProviderMetrics(
            $this->rootDir . '/storage',
            (int) $config->get('TWELVEDATA_DAILY_LIMIT', 800),
            (int) $config->get('EODHD_DAILY_LIMIT', 20),
            (int) $config->get('ALPHAVANTAGE_DAILY_LIMIT', 25)
        );
        $quoteCache = new \FinHub\Infrastructure\MarketData\QuoteCache(
            $this->rootDir . '/storage/quote_cache',
            86400
        );
        $symbolsAggregator = new \FinHub\Infrastructure\MarketData\QuoteSymbolsAggregator(
            $eodhdClient,
            $twelveDataClient,
            $quoteCache
        );
        $providerOrder = $config->get('PRICE_PROVIDER_ORDER', 'eodhd,twelvedata,alphavantage');
        $twelveProvider = new TwelveDataProvider($twelveDataClient);
        $eodhdProvider = new EodhdProvider($eodhdClient);
        $alphaProvider = new AlphaVantageProvider($alphaClient);
        $quoteProviders = [$twelveProvider, $eodhdProvider, $alphaProvider];
        $priceService = new PriceService(
            $twelveDataClient,
            $eodhdClient,
            $metrics,
            $quoteCache,
            $symbolsAggregator,
            $providerOrder,
            $alphaClient,
            $quoteProviders,
            $twelveProvider
        );
        $providerUsageService = new ProviderUsageService($twelveDataClient, $eodhdClient, $metrics, $logger);
        $polygonService = new PolygonService($polygonClient);
        $tiingoService = new TiingoService($tiingoClient);
        $stooqService = new StooqService($stooqClient);
        $portfolioRepository = new PdoPortfolioRepository($pdo);
        $priceSnapshotRepository = new PdoPriceSnapshotRepository($pdo, $logger);
        $instrumentCatalogRepository = new PdoInstrumentCatalogRepository($pdo);
        $portfolioService = new PortfolioService($portfolioRepository);
        $ingestBatchSize = (int) $config->get('DATALAKE_INGEST_BATCH_SIZE', 10);
        $dataLakeService = new DataLakeService(
            $priceSnapshotRepository,
            $priceService,
            $logger,
            $ingestBatchSize,
            $ravaCedearsService,
            $ravaAccionesService,
            $ravaBonosService
        );
        $instrumentCatalogService = new InstrumentCatalogService($ravaCedearsService, $ravaAccionesService, $ravaBonosService, $instrumentCatalogRepository, $logger, $portfolioService, $priceService);
        $portfolioSummaryService = new PortfolioSummaryService($portfolioService, $dataLakeService, $priceService, $logger);
        $portfolioSectorService = new PortfolioSectorService($portfolioService, $priceService, $logger);
        $portfolioHeatmapService = new PortfolioHeatmapService($portfolioService, $portfolioSummaryService, $portfolioSectorService, $priceService, $tiingoService, $logger);
        $predictionService = new PredictionService($predictionRepository, $predictionRunRepository, $portfolioService, $dataLakeService, $userRepository);
        $signalRepository = new PdoSignalRepository($pdo, $logger);
        $signalService = new SignalService($signalRepository, $dataLakeService, $logger);
        $backtestRepository = new PdoBacktestRepository($pdo, $logger);
        $backtestService = new BacktestService($backtestRepository, $logger);

        return new Container([
            'config' => $config,
            'pdo' => $pdo,
            'logger' => $logger,
            'jwt' => $jwt,
            'password_hasher' => $passwordHasher,
            'user_repository' => $userRepository,
            'user_deletion_service' => $userDeletionService,
            'mail_sender' => $mailSender,
            'activation_service' => $activationService,
            'price_service' => $priceService,
            'eodhd_client' => $eodhdClient,
            'provider_metrics' => $metrics,
            'quote_cache' => $quoteCache,
            'provider_usage' => $providerUsageService,
            'symbols_aggregator' => $symbolsAggregator,
            'quote_providers' => $quoteProviders,
            'fx_provider' => $twelveProvider,
            'portfolio_repository' => $portfolioRepository,
            'price_snapshot_repository' => $priceSnapshotRepository,
            'instrument_catalog_repository' => $instrumentCatalogRepository,
            'portfolio_service' => $portfolioService,
            'portfolio_summary_service' => $portfolioSummaryService,
            'portfolio_sector_service' => $portfolioSectorService,
            'portfolio_heatmap_service' => $portfolioHeatmapService,
            'prediction_service' => $predictionService,
            'prediction_repository' => $predictionRepository,
            'prediction_run_repository' => $predictionRunRepository,
            'signal_repository' => $signalRepository,
            'signal_service' => $signalService,
            'backtest_repository' => $backtestRepository,
            'backtest_service' => $backtestService,
            'datalake_service' => $dataLakeService,
            'instrument_catalog_service' => $instrumentCatalogService,
            'rava_cedears_service' => $ravaCedearsService,
            'rava_acciones_service' => $ravaAccionesService,
            'rava_bonos_service' => $ravaBonosService,
            'rava_historicos_service' => $ravaHistoricosService,
            'polygon_service' => $polygonService,
            'tiingo_service' => $tiingoService,
            'stooq_service' => $stooqService,
            'polygon_client' => $polygonClient,
            'tiingo_client' => $tiingoClient,
            'stooq_client' => $stooqClient,
        ]);
    }

    /**
     * Carga las variables del archivo `.env` en un array asociativo ignorando comentarios o líneas vacías.
     */
    private function loadEnvironment(): array
    {
        $envFile = $this->rootDir . '/.env';
        $env = [];
        if (!file_exists($envFile)) {
            return $env;
        }

        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$key, $value] = explode('=', $line, 2);
            $env[trim($key)] = trim($value);
        }

        return $env;
    }

    /**
     * Normaliza rutas de log en relación al directorio raíz y evita salirse del árbol del proyecto.
     */
    private function normalizeLogPath(?string $input): string
    {
        $root = rtrim($this->rootDir, '/');
        $default = $root . '/storage/logs';
        $path = $input !== null ? trim($input) : '';
        if ($path === '') {
            return $default;
        }
        $base = str_replace('\\', '/', $path);
        $candidate = str_starts_with($base, '/') ? $base : $root . '/' . ltrim($base, './');

        $segments = [];
        foreach (explode('/', $candidate) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        if (empty($segments)) {
            return $default;
        }

        $resolved = '/' . implode('/', $segments);
        if (!str_starts_with($resolved, $root)) {
            return $default;
        }

        return rtrim($resolved, '/');
    }
}
