<?php
declare(strict_types=1);

namespace FinHub\Infrastructure;

use FinHub\Application\Auth\AuthService;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;

final class ApiDispatcher
{
    private Config $config;
    private LoggerInterface $logger;
    private AuthService $authService;
    /** Rutas base deben terminar sin barra final. */
    private string $apiBase;

    public function __construct(Config $config, LoggerInterface $logger, AuthService $authService)
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->apiBase = rtrim($config->get('API_BASE_PATH', '/api'), '/');
        $this->authService = $authService;
    }

    /**
     * Ejecuta el ciclo completo de la petición: CORS, routing y respuestas JSON.
     */
    public function dispatch(string $traceId): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $this->sendCorsHeaders();
        if ($method === 'OPTIONS') {
            $this->sendJson(['result' => 'ok'], 204);
            return;
        }
        $path = $this->getRoutePath($uri);
        try {
            $this->route($method, $path, $traceId);
        } catch (\Throwable $throwable) {
            $this->logRequestError($method, $path, $traceId, $throwable);
            $this->sendError($throwable, $traceId);
        }
    }

    /**
     * Define la lógica de enrutamiento y genera las respuestas específicas.
     */
    private function route(string $method, string $path, string $traceId): void
    {
        if ($method === 'GET' && $path === '/health') {
            $this->sendJson(['status' => 'ok', 'trace_id' => $traceId]);
            return;
        }
        if ($method === 'GET' && $path === '/status') {
            $this->sendJson([
                'status' => 'ok',
                'env' => $this->config->get('APP_ENV', 'production'),
                'trace_id' => $traceId,
            ]);
            return;
        }
        if ($method === 'POST' && $path === '/auth/login') {
            $this->handleLogin();
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    /**
     * Envía el payload JSON con los encabezados apropiados y código HTTP.
     */
    private function sendJson(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Calcula el path relativo al API base configurado.
     */
    private function getRoutePath(string $uri): string
    {
        if ($this->apiBase !== '' && str_starts_with($uri, $this->apiBase)) {
            return '/' . trim(substr($uri, strlen($this->apiBase)), '/');
        }
        return $uri;
    }

    private function handleLogin(): void
    {
        $data = $this->parseJsonBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new \RuntimeException('Email y contraseña requeridos', 422);
        }

        $payload = $this->authService->authenticate($email, $password);
        $this->sendJson($payload);
    }

    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('JSON inválido', 400);
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Encapsula la lógica de CORS centralizada a partir de configuraciones.
     */
    private function sendCorsHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . ($this->config->get('CORS_ALLOWED_ORIGINS', '*')));
        header('Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type,Authorization,X-CRON-TOKEN');
    }

    /**
     * Loguea detalles de error cuando la ruta o método no se pueden procesar.
     */
    private function logRequestError(string $method, string $path, string $traceId, \Throwable $throwable): void
    {
        $this->logger->error('request.error', [
            'trace_id' => $traceId,
            'message' => $throwable->getMessage(),
            'path' => $path,
            'method' => $method,
        ]);
    }

    /**
     * Envía el payload de error HTTP respetando el código proporcionado.
     */
    private function sendError(\Throwable $throwable, string $traceId): void
    {
        $code = (int) $throwable->getCode();
        $status = $code >= 100 && $code < 600 ? $code : 500;
        $message = $status === 404 ? 'not_found' : 'unexpected_error';
        $payload = [
            'error' => [
                'code' => $message,
                'message' => $throwable->getMessage(),
                'trace_id' => $traceId,
            ],
        ];
        $this->sendJson($payload, $status);
    }
}
