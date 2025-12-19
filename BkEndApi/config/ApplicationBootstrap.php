<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Config;

use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Config\Container;
use FinHub\Infrastructure\Logging\FileLogger;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;

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

        return new Container([
            'config' => $config,
            'pdo' => $pdo,
            'logger' => $logger,
            'jwt' => $jwt,
            'password_hasher' => $passwordHasher,
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
