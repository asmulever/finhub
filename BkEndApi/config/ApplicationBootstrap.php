<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Config;

use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Config\Container;
use FinHub\Infrastructure\Logging\FileLogger;
use FinHub\Application\Backtest\BacktestService;
use FinHub\Application\Auth\ActivationService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Application\Portfolio\PortfolioSummaryService;
use FinHub\Application\Portfolio\PortfolioHeatmapService;
use FinHub\Application\Signals\SignalService;
use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\DataLake\InstrumentCatalogService;
use FinHub\Application\LLM\OpenRouterClient;
use FinHub\Application\LLM\MoonshotClient;
use FinHub\Application\Analytics\PredictionService;
use FinHub\Application\Analytics\PredictionMarketService;
use FinHub\Application\Analytics\PredictionMarketFetcherInterface;
use FinHub\Application\Analytics\PredictionMarketRepositoryInterface;
use FinHub\Application\Ingestion\DataReadinessService;
use FinHub\Application\MarketData\RavaViewsService;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;
use FinHub\Infrastructure\Portfolio\PdoPortfolioRepository;
use FinHub\Infrastructure\DataLake\NullPriceSnapshotRepository;
use FinHub\Infrastructure\DataLake\PdoInstrumentCatalogRepository;
use FinHub\Infrastructure\User\PdoUserRepository;
use FinHub\Infrastructure\User\UserDeletionService;
use FinHub\Infrastructure\Mail\BrevoMailSender;
use FinHub\Infrastructure\Analytics\PdoPredictionRepository;
use FinHub\Infrastructure\Analytics\PdoPredictionRunRepository;
use FinHub\Infrastructure\Analytics\HttpYahooPredictionFetcher;
use FinHub\Infrastructure\Analytics\PdoPredictionMarketRepository;
use FinHub\Infrastructure\Signals\PdoSignalRepository;
use FinHub\Infrastructure\Backtest\PdoBacktestRepository;
use FinHub\Infrastructure\MarketData\RavaViewsClient;

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
        $portfolioRepository = new PdoPortfolioRepository($pdo);
        $priceSnapshotRepository = new NullPriceSnapshotRepository();
        $ingestBatchSize = (int) $config->get('DATALAKE_INGEST_BATCH_SIZE', 10);
        $dataLakeService = new DataLakeService(
            $priceSnapshotRepository,
            $logger,
            $ingestBatchSize
        );
        $openRouterClient = new OpenRouterClient(
            $config->require('OPENROUTER_API_KEY'),
            $logger,
            $config->get('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
            $config->get('OPENROUTER_REFERER', 'https://finhub.local'),
            $config->get('OPENROUTER_TITLE', 'FinHub Radar')
        );
        $moonshotClient = new MoonshotClient(
            $config->require('MOONSHOT_API_KEY'),
            $logger,
            $config->get('MOONSHOT_BASE_URL', 'https://api.moonshot.ai/v1')
        );
        $predictionMarketFetcher = new HttpYahooPredictionFetcher($logger);
        $predictionMarketRepository = new PdoPredictionMarketRepository($pdo, $logger);
        $predictionMarketService = new PredictionMarketService($predictionMarketFetcher, $predictionMarketRepository, $logger);
        $instrumentCatalogRepository = new PdoInstrumentCatalogRepository($pdo);
        $portfolioService = new PortfolioService($portfolioRepository);
        $ravaViewsClient = new RavaViewsClient($config);
        $ravaViewsService = new RavaViewsService($ravaViewsClient, $logger);
        $instrumentCatalogService = new InstrumentCatalogService($instrumentCatalogRepository, $logger, $portfolioService);
        $portfolioSummaryService = new PortfolioSummaryService($portfolioService, $dataLakeService, $logger);
        $portfolioHeatmapService = new PortfolioHeatmapService($portfolioService, $portfolioSummaryService, $logger);
        $predictionService = new PredictionService($predictionRepository, $predictionRunRepository, $portfolioService, $dataLakeService, $userRepository);
        $signalRepository = new PdoSignalRepository($pdo, $logger);
        $signalService = new SignalService($signalRepository, $dataLakeService, $logger);
        $backtestRepository = new PdoBacktestRepository($pdo, $logger);
        $backtestService = new BacktestService($backtestRepository, $logger);
        $dataReadinessService = new DataReadinessService($logger);

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
            'portfolio_repository' => $portfolioRepository,
            'price_snapshot_repository' => $priceSnapshotRepository,
            'prediction_market_fetcher' => $predictionMarketFetcher,
            'prediction_market_repository' => $predictionMarketRepository,
            'prediction_market_service' => $predictionMarketService,
            'rava_views_service' => $ravaViewsService,
            'instrument_catalog_repository' => $instrumentCatalogRepository,
            'portfolio_service' => $portfolioService,
            'portfolio_summary_service' => $portfolioSummaryService,
            'portfolio_heatmap_service' => $portfolioHeatmapService,
            'prediction_service' => $predictionService,
            'prediction_repository' => $predictionRepository,
            'prediction_run_repository' => $predictionRunRepository,
            'signal_repository' => $signalRepository,
            'signal_service' => $signalService,
            'backtest_repository' => $backtestRepository,
            'backtest_service' => $backtestService,
            'datalake_service' => $dataLakeService,
            'openrouter_client' => $openRouterClient,
            'moonshot_client' => $moonshotClient,
            'instrument_catalog_service' => $instrumentCatalogService,
            'data_readiness_service' => $dataReadinessService,
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
