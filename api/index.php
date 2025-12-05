<?php

declare(strict_types=1);

use App\Infrastructure\ApplicationContainerFactory;
use App\Infrastructure\DatabaseManager;
use App\Infrastructure\Router;

require __DIR__ . '/../App/vendor/autoload.php';
load_env();

require_once __DIR__ . '/../App/Infrastructure/SecurityHeaders.php';
apply_security_headers();

$container = ApplicationContainerFactory::create();

DatabaseManager::getConnection();

$router = new Router($container);
$router->dispatch($_SERVER['REQUEST_URI'] ?? '/', $_SERVER['REQUEST_METHOD'] ?? 'GET');
