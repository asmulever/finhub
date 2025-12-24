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
    $pdo,
    $eodhdClient
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
