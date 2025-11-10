<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/Infrastructure/Config.php';
require_once __DIR__ . '/../App/Infrastructure/DatabaseConnection.php';
require_once __DIR__ . '/../App/Domain/UserRepository.php';
require_once __DIR__ . '/../App/Infrastructure/MysqlUserRepository.php';
require_once __DIR__ . '/../App/Domain/FinancialObjectRepository.php';
require_once __DIR__ . '/../App/Infrastructure/MysqlFinancialObjectRepository.php';
require_once __DIR__ . '/../App/Infrastructure/JwtService.php';
require_once __DIR__ . '/../App/Application/AuthService.php';
require_once __DIR__ . '/../App/Application/FinancialObjectService.php';
require_once __DIR__ . '/../App/Interfaces/AuthController.php';
require_once __DIR__ . '/../App/Interfaces/FinancialObjectController.php';
require_once __DIR__ . '/../App/Domain/User.php';
require_once __DIR__ . '/../App/Domain/FinancialObject.php';

use App\Application\AuthService;
use App\Application\FinancialObjectService;
use App\Infrastructure\JwtService;
use App\Infrastructure\MysqlFinancialObjectRepository;
use App\Infrastructure\MysqlUserRepository;
use App\Interfaces\AuthController;
use App\Interfaces\FinancialObjectController;

header("Content-Type: application/json; charset=UTF-8");

$requestUri = $_SERVER['REQUEST_URI'];
$requestMethod = $_SERVER['REQUEST_METHOD'];

// Simple router
$uri = strtok($requestUri, '?');

// Dependencies
$userRepository = new MysqlUserRepository();
$financialObjectRepository = new MysqlFinancialObjectRepository();
$jwtService = new JwtService(\App\Infrastructure\Config::get('JWT_SECRET'));
$authService = new AuthService($userRepository, $jwtService);
$financialObjectService = new FinancialObjectService($financialObjectRepository);
$authController = new AuthController($authService);
$financialObjectController = new FinancialObjectController($financialObjectService, $jwtService);

if ($uri === '/auth/validate' && $requestMethod === 'POST') {
    $authController->validate();
} elseif ($uri === '/financial-objects' && $requestMethod === 'GET') {
    $financialObjectController->list();
} else {
    http_response_code(404);
    echo json_encode(['error' => 'Not Found']);
}
