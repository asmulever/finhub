<?php
declare(strict_types=1);

// Mostrar errores en entorno de desarrollo
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

require_once __DIR__ . '/App/Infrastructure/Config.php';
require_once __DIR__ . '/App/Infrastructure/DatabaseConnection.php';
require_once __DIR__ . '/App/Domain/UserRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlUserRepository.php';
require_once __DIR__ . '/App/Domain/FinancialObjectRepository.php';
require_once __DIR__ . '/App/Infrastructure/MysqlFinancialObjectRepository.php';
require_once __DIR__ . '/App/Infrastructure/JwtService.php';
require_once __DIR__ . '/App/Application/AuthService.php';
require_once __DIR__ . '/App/Application/FinancialObjectService.php';
require_once __DIR__ . '/App/Interfaces/AuthController.php';
require_once __DIR__ . '/App/Interfaces/FinancialObjectController.php';
require_once __DIR__ . '/App/Domain/User.php';
require_once __DIR__ . '/App/Domain/FinancialObject.php';

use App\Application\AuthService;
use App\Application\FinancialObjectService;
use App\Infrastructure\JwtService;
use App\Infrastructure\MysqlFinancialObjectRepository;
use App\Infrastructure\MysqlUserRepository;
use App\Interfaces\AuthController;
use App\Interfaces\FinancialObjectController;

header("Content-Type: application/json; charset=UTF-8");

// Captura global de excepciones
try {
    $requestUri = $_SERVER['REQUEST_URI'] ?? '/';
    $requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    $uri = strtok($requestUri, '?');

    // Dependencias
    $userRepository = new MysqlUserRepository();
    $financialObjectRepository = new MysqlFinancialObjectRepository();
    $jwtService = new JwtService(\App\Infrastructure\Config::get('JWT_SECRET'));
    $authService = new AuthService($userRepository, $jwtService);
    $financialObjectService = new FinancialObjectService($financialObjectRepository);
    $authController = new AuthController($authService);
    $financialObjectController = new FinancialObjectController($financialObjectService, $jwtService);

    // Router simple
    if ($uri === '/auth/validate' && $requestMethod === 'POST') {
        $authController->validate();
    } elseif ($uri === '/financial-objects' && $requestMethod === 'GET') {
        $financialObjectController->list();
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
