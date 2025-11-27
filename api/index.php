<?php

declare(strict_types=1);

use App\Application\AccountService;
use App\Application\AuthService;
use App\Application\FinancialObjectService;
use App\Application\LogService;
use App\Application\PortfolioService;
use App\Application\QuotesService;
use App\Application\UserService;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\FinancialObjectRepositoryInterface;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\PortfolioTickerRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Config;
use App\Infrastructure\Container;
use App\Infrastructure\EnvManager;
use App\Infrastructure\FinnhubService;
use App\Infrastructure\DatabaseManager;
use App\Infrastructure\Repository\MysqlAccountRepository;
use App\Infrastructure\Repository\MysqlFinancialObjectRepository;
use App\Infrastructure\Repository\MysqlLogRepository;
use App\Infrastructure\Repository\MysqlPortfolioTickerRepository;
use App\Infrastructure\Repository\MysqlUserRepository;
use App\Infrastructure\Router;
use App\Infrastructure\JwtService;
use App\Interfaces\AccountController;
use App\Interfaces\AuthController;
use App\Interfaces\FinancialObjectController;
use App\Interfaces\LogController;
use App\Interfaces\QuotesController;
use App\Interfaces\SettingsController;
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
$container->set(EnvManager::class, fn() => new EnvManager());

$container->set(UserRepositoryInterface::class, fn() => new MysqlUserRepository());
$container->set(FinancialObjectRepositoryInterface::class, fn() => new MysqlFinancialObjectRepository());
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
$container->set(QuotesService::class, fn(Container $c) => new QuotesService(
    $c->get(FinnhubService::class),
    __DIR__ . '/../storage/cache/quotes',
    (bool)Config::get('CRON_ACTIVO', false),
    max(60, (int)Config::get('CRON_INTERVALO', 60)),
    Config::get('CRON_HR_START', '09:00'),
    Config::get('CRON_HR_END', '18:00')
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
$container->set(QuotesController::class, fn(Container $c) => new QuotesController(
    $c->get(QuotesService::class)
));
$container->set(SettingsController::class, fn(Container $c) => new SettingsController(
    $c->get(EnvManager::class),
    $c->get(JwtService::class)
));

DatabaseManager::getConnection();

$router = new Router($container);
$router->dispatch($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
