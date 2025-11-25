<?php
declare(strict_types=1);

// Mostrar errores en entorno de desarrollo
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/App/Infrastructure/Config.php';
require_once __DIR__ . '/App/Infrastructure/DatabaseConnection.php';
require_once __DIR__ . '/App/Infrastructure/Logger.php';
require_once __DIR__ . '/App/Infrastructure/SchemaManager.php';
require_once __DIR__ . '/App/Domain/Account.php';
require_once __DIR__ . '/App/Domain/UserRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlUserRepository.php';
require_once __DIR__ . '/App/Domain/FinancialObjectRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlFinancialObjectRepository.php';
require_once __DIR__ . '/App/Infrastructure/JwtService.php';
require_once __DIR__ . '/App/Domain/AccountRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlAccountRepository.php';
require_once __DIR__ . '/App/Domain/Portfolio.php';
require_once __DIR__ . '/App/Domain/PortfolioRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlPortfolioRepository.php';
require_once __DIR__ . '/App/Domain/PortfolioTicker.php';
require_once __DIR__ . '/App/Domain/PortfolioTickerRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlPortfolioTickerRepository.php';
require_once __DIR__ . '/App/Application/AuthService.php';
require_once __DIR__ . '/App/Application/FinancialObjectService.php';
require_once __DIR__ . '/App/Application/UserService.php';
require_once __DIR__ . '/App/Application/AccountService.php';
require_once __DIR__ . '/App/Application/PortfolioService.php';
require_once __DIR__ . '/App/Interfaces/BaseController.php';
require_once __DIR__ . '/App/Interfaces/AuthController.php';
require_once __DIR__ . '/App/Interfaces/FinancialObjectController.php';
require_once __DIR__ . '/App/Interfaces/UserController.php';
require_once __DIR__ . '/App/Interfaces/AccountController.php';
require_once __DIR__ . '/App/Interfaces/PortfolioController.php';
require_once __DIR__ . '/App/Domain/User.php';
require_once __DIR__ . '/App/Domain/FinancialObject.php';

use App\Application\AuthService;
use App\Application\FinancialObjectService;
use App\Application\AccountService;
use App\Application\PortfolioService;
use App\Application\UserService;
use App\Infrastructure\JwtService;
use App\Infrastructure\MysqlAccountRepository;
use App\Infrastructure\MysqlFinancialObjectRepository;
use App\Infrastructure\MysqlPortfolioRepository;
use App\Infrastructure\MysqlPortfolioTickerRepository;
use App\Infrastructure\MysqlUserRepository;
use App\Infrastructure\SchemaManager;
use App\Interfaces\AuthController;
use App\Interfaces\AccountController;
use App\Interfaces\FinancialObjectController;
use App\Interfaces\PortfolioController;
use App\Interfaces\UserController;

header("Content-Type: application/json; charset=UTF-8");

// Captura global de excepciones
try {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';

    $uri = strtok($requestUri, '?');
    // Normaliza cuando se invoca como /index.php/endpoint
    if (str_starts_with($uri, '/index.php')) {
        $uri = substr($uri, strlen('/index.php'));
        if ($uri === '') {
            $uri = '/';
        }
    }

    // Garantiza esquema y datos mínimos
    $healthStatus = SchemaManager::ensureSchema();

    // Dependencias
    $userRepository = new MysqlUserRepository();
    $financialObjectRepository = new MysqlFinancialObjectRepository();
    $jwtService = new JwtService(\App\Infrastructure\Config::getRequired('JWT_SECRET'));
    $authService = new AuthService($userRepository, $jwtService);
    $accountRepository = new MysqlAccountRepository();
    $portfolioRepository = new MysqlPortfolioRepository();
    $portfolioTickerRepository = new MysqlPortfolioTickerRepository();
    $financialObjectService = new FinancialObjectService($financialObjectRepository);
    $userService = new UserService($userRepository);
    $accountService = new AccountService($accountRepository, $userRepository, $portfolioRepository);
    $portfolioService = new PortfolioService($portfolioRepository, $portfolioTickerRepository, $financialObjectRepository);
    $authController = new AuthController($authService);
    $financialObjectController = new FinancialObjectController($financialObjectService, $jwtService);
    $userController = new UserController($userService, $jwtService);
    $accountController = new AccountController($accountService, $jwtService);
    $portfolioController = new PortfolioController($portfolioService, $jwtService);

    // Router simple
    if (($uri === '/auth/login' || $uri === '/auth/validate') && $requestMethod === 'POST') {
        $authController->login();
    } elseif ($uri === '/auth/refresh' && $requestMethod === 'POST') {
        $authController->refresh();
    } elseif ($uri === '/auth/session' && $requestMethod === 'GET') {
        $authController->session();
    } elseif ($uri === '/auth/logout' && $requestMethod === 'POST') {
        $authController->logout();
    } elseif ($uri === '/health' && $requestMethod === 'GET') {
        http_response_code(200);
        echo json_encode([
            'status' => 'ok',
            'environment' => getenv('APP_ENV') ?: 'production',
            'database' => $healthStatus,
        ]);
    } elseif ($uri === '/financial-objects' && $requestMethod === 'GET') {
        $financialObjectController->list();
    } elseif ($uri === '/financial-objects' && $requestMethod === 'POST') {
        $financialObjectController->create();
    } elseif (preg_match('#^/financial-objects/(\d+)$#', $uri, $matches)) {
        $id = (int)$matches[1];
        if ($requestMethod === 'PUT') {
            $financialObjectController->update($id);
        } elseif ($requestMethod === 'DELETE') {
            $financialObjectController->delete($id);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
    } elseif ($uri === '/accounts' && $requestMethod === 'GET') {
        $accountController->list();
    } elseif ($uri === '/accounts' && $requestMethod === 'POST') {
        $accountController->create();
    } elseif (preg_match('#^/accounts/(\d+)/(update|edit)$#', $uri, $matches) && $requestMethod === 'POST') {
        $id = (int)$matches[1];
        $accountController->update($id);
    } elseif (preg_match('#^/accounts/(\d+)/(delete|remove)$#', $uri, $matches) && $requestMethod === 'POST') {
        $id = (int)$matches[1];
        $accountController->delete($id);
    } elseif (preg_match('#^/accounts/(\d+)$#', $uri, $matches)) {
        $id = (int)$matches[1];
        if ($requestMethod === 'PUT') {
            $accountController->update($id);
        } elseif ($requestMethod === 'DELETE') {
            $accountController->delete($id);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
    } elseif ($uri === '/portfolio' && $requestMethod === 'GET') {
        $portfolioController->show();
    } elseif ($uri === '/portfolio/tickers' && $requestMethod === 'POST') {
        $portfolioController->addTicker();
    } elseif (preg_match('#^/portfolio/tickers/(\d+)$#', $uri, $matches)) {
        $tickerId = (int)$matches[1];
        if ($requestMethod === 'PUT') {
            $portfolioController->updateTicker($tickerId);
        } elseif ($requestMethod === 'DELETE') {
            $portfolioController->deleteTicker($tickerId);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
    } elseif ($uri === '/users' && $requestMethod === 'GET') {
        $userController->list();
    } elseif ($uri === '/users' && $requestMethod === 'POST') {
        $userController->create();
    } elseif (preg_match('#^/users/(\d+)/(delete|remove)$#', $uri, $matches) && $requestMethod === 'POST') {
        $id = (int)$matches[1];
        $userController->delete($id);
    } elseif (preg_match('#^/users/(\d+)/(update|edit)$#', $uri, $matches) && $requestMethod === 'POST') {
        $id = (int)$matches[1];
        $userController->update($id);
    } elseif (preg_match('#^/users/(\d+)$#', $uri, $matches)) {
        $id = (int)$matches[1];
        if ($requestMethod === 'PUT') {
            $userController->update($id);
        } elseif ($requestMethod === 'DELETE') {
            $userController->delete($id);
        } else {
            http_response_code(405);
            echo json_encode(['error' => 'Method Not Allowed']);
        }
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Not Found']);
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Internal Server Error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString())
    ]);
}
