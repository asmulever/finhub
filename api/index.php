<?php

declare(strict_types=1);

use App\Application\AccountService;
use App\Application\AuthService;
use App\Application\FinancialObjectService;
use App\Application\EtlJobRunner;
use App\Application\EtlIngestService;
use App\Application\EtlNormalizeService;
use App\Application\EtlIndicatorService;
use App\Application\EtlSignalService;
use App\Application\StubFinnhubPriceSourceClient;
use App\Application\StubRavaPriceSourceClient;
use App\Application\FinnhubPriceDataSource;
use App\Application\LogService;
use App\Application\PortfolioService;
use App\Application\UserService;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\FinancialObjectRepositoryInterface;
use App\Domain\Repository\InstrumentRepositoryInterface;
use App\Domain\Repository\InstrumentSourceMapRepositoryInterface;
use App\Domain\Repository\CalendarRepositoryInterface;
use App\Domain\Repository\StagingPriceRepositoryInterface;
use App\Domain\Repository\PriceDailyRepositoryInterface;
use App\Domain\Repository\IndicatorDailyRepositoryInterface;
use App\Domain\Repository\SignalDailyRepositoryInterface;
use App\Domain\Repository\EtlRunLogRepositoryInterface;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\PortfolioTickerRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Config;
use App\Infrastructure\Container;
use App\Infrastructure\FinnhubService;
use App\Infrastructure\FinnhubHttpClient;
use App\Infrastructure\DatabaseManager;
use App\Infrastructure\Repository\MysqlAccountRepository;
use App\Infrastructure\Repository\MysqlFinancialObjectRepository;
use App\Infrastructure\Repository\MysqlInstrumentRepository;
use App\Infrastructure\Repository\MysqlInstrumentSourceMapRepository;
use App\Infrastructure\Repository\MysqlCalendarRepository;
use App\Infrastructure\Repository\MysqlStagingPriceRepository;
use App\Infrastructure\Repository\MysqlPriceDailyRepository;
use App\Infrastructure\Repository\MysqlIndicatorDailyRepository;
use App\Infrastructure\Repository\MysqlSignalDailyRepository;
use App\Infrastructure\Repository\MysqlEtlRunLogRepository;
use App\Infrastructure\Repository\MysqlLogRepository;
use App\Infrastructure\Repository\MysqlPortfolioTickerRepository;
use App\Infrastructure\Repository\MysqlUserRepository;
use App\Infrastructure\Router;
use App\Infrastructure\JwtService;
use App\Interfaces\AccountController;
use App\Interfaces\AuthController;
use App\Interfaces\FinancialObjectController;
use App\Interfaces\EtlController;
use App\Interfaces\LogController;
use App\Interfaces\PortfolioController;
use App\Interfaces\UserController;

require __DIR__ . '/../App/vendor/autoload.php';
load_env();

require_once __DIR__ . '/../App/Infrastructure/SecurityHeaders.php';
apply_security_headers();

$container = new Container();

$container->set(LogRepositoryInterface::class, fn() => new MysqlLogRepository());
$container->set(LogService::class, function (Container $c): LogService {
    $service = new LogService($c->get(LogRepositoryInterface::class));
    LogService::registerInstance($service);
    return $service;
});

$container->set(JwtService::class, fn() => new JwtService(Config::getRequired('JWT_SECRET')));
$container->set(FinnhubService::class, fn() => new FinnhubService(
    Config::getRequired('FINNHUB_API_KEY'),
    Config::get('X_FINNHUB_SECRET')
));
$container->set(FinnhubHttpClient::class, fn() => new FinnhubHttpClient(
    Config::getRequired('FINNHUB_API_KEY'),
    Config::get('X_FINNHUB_SECRET')
));

$container->set(UserRepositoryInterface::class, fn() => new MysqlUserRepository());
$container->set(FinancialObjectRepositoryInterface::class, fn() => new MysqlFinancialObjectRepository());
$container->set(InstrumentRepositoryInterface::class, fn() => new MysqlInstrumentRepository());
$container->set(InstrumentSourceMapRepositoryInterface::class, fn() => new MysqlInstrumentSourceMapRepository());
$container->set(CalendarRepositoryInterface::class, fn() => new MysqlCalendarRepository());
$container->set(StagingPriceRepositoryInterface::class, fn() => new MysqlStagingPriceRepository());
$container->set(PriceDailyRepositoryInterface::class, fn() => new MysqlPriceDailyRepository());
$container->set(IndicatorDailyRepositoryInterface::class, fn() => new MysqlIndicatorDailyRepository());
$container->set(SignalDailyRepositoryInterface::class, fn() => new MysqlSignalDailyRepository());
$container->set(EtlRunLogRepositoryInterface::class, fn() => new MysqlEtlRunLogRepository());
$container->set(AccountRepositoryInterface::class, fn() => new MysqlAccountRepository());
$container->set(PortfolioTickerRepositoryInterface::class, fn() => new MysqlPortfolioTickerRepository());

$container->set(AuthService::class, fn(Container $c) => new AuthService(
    $c->get(UserRepositoryInterface::class),
    $c->get(JwtService::class)
));

$container->set(UserService::class, fn(Container $c) => new UserService(
    $c->get(UserRepositoryInterface::class)
));

$container->set(AccountService::class, fn(Container $c) => new AccountService(
    $c->get(AccountRepositoryInterface::class),
    $c->get(UserRepositoryInterface::class)
));

$container->set(FinancialObjectService::class, fn(Container $c) => new FinancialObjectService(
    $c->get(FinancialObjectRepositoryInterface::class)
));

$container->set(PortfolioService::class, fn(Container $c) => new PortfolioService(
    $c->get(AccountRepositoryInterface::class),
    $c->get(PortfolioTickerRepositoryInterface::class),
    $c->get(FinancialObjectRepositoryInterface::class)
));
$container->set(EtlJobRunner::class, fn(Container $c) => new EtlJobRunner(
    $c->get(EtlRunLogRepositoryInterface::class),
    $c->get(LogService::class)
));

$container->set(StubFinnhubPriceSourceClient::class, fn() => new StubFinnhubPriceSourceClient());
$container->set(StubRavaPriceSourceClient::class, fn() => new StubRavaPriceSourceClient());

$container->set(FinnhubPriceDataSource::class, fn(Container $c) => new FinnhubPriceDataSource(
    $c->get(FinnhubHttpClient::class)
));

$container->set(EtlIngestService::class, fn(Container $c) => new EtlIngestService(
    $c->get(InstrumentSourceMapRepositoryInterface::class),
    $c->get(StagingPriceRepositoryInterface::class),
    $c->get(FinnhubPriceDataSource::class),
    $c->get(StubRavaPriceSourceClient::class)
));

$container->set(EtlNormalizeService::class, fn(Container $c) => new EtlNormalizeService(
    $c->get(InstrumentSourceMapRepositoryInterface::class),
    $c->get(CalendarRepositoryInterface::class),
    $c->get(StagingPriceRepositoryInterface::class),
    $c->get(PriceDailyRepositoryInterface::class)
));

$container->set(EtlIndicatorService::class, fn(Container $c) => new EtlIndicatorService(
    $c->get(InstrumentRepositoryInterface::class),
    $c->get(PriceDailyRepositoryInterface::class),
    $c->get(IndicatorDailyRepositoryInterface::class),
    $c->get(CalendarRepositoryInterface::class)
));

$container->set(EtlSignalService::class, fn(Container $c) => new EtlSignalService(
    $c->get(InstrumentRepositoryInterface::class),
    $c->get(CalendarRepositoryInterface::class),
    $c->get(IndicatorDailyRepositoryInterface::class),
    $c->get(PriceDailyRepositoryInterface::class),
    $c->get(SignalDailyRepositoryInterface::class)
));

$container->set(AuthController::class, fn(Container $c) => new AuthController($c->get(AuthService::class)));
$container->set(UserController::class, fn(Container $c) => new UserController(
    $c->get(UserService::class),
    $c->get(JwtService::class)
));
$container->set(AccountController::class, fn(Container $c) => new AccountController(
    $c->get(AccountService::class),
    $c->get(JwtService::class)
));
$container->set(FinancialObjectController::class, fn(Container $c) => new FinancialObjectController(
    $c->get(FinancialObjectService::class),
    $c->get(JwtService::class)
));
$container->set(PortfolioController::class, fn(Container $c) => new PortfolioController(
    $c->get(PortfolioService::class),
    $c->get(JwtService::class)
));
$container->set(LogController::class, fn(Container $c) => new LogController(
    $c->get(LogService::class),
    $c->get(JwtService::class)
));
// QuotesService, QuotesController, SettingsController y EnvManager han sido eliminados
$container->set(EtlController::class, fn(Container $c) => new EtlController(
    $c->get(EtlJobRunner::class),
    $c->get(EtlIngestService::class),
    $c->get(EtlNormalizeService::class),
    $c->get(EtlIndicatorService::class),
    $c->get(EtlSignalService::class)
));

DatabaseManager::getConnection();

$router = new Router($container);
$router->dispatch($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
