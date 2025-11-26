<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\LogService;
use App\Infrastructure\RequestContext;
use App\Infrastructure\JwtService;

class LogController extends BaseController
{
    private const FRONTEND_LEVELS = ['debug', 'info', 'warning', 'error'];

    public function __construct(
        private readonly LogService $logService,
        private readonly JwtService $jwtService
    )
    {
    }

    public function list(): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $filters = [
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'level' => $_GET['level'] ?? null,
            'http_status' => (isset($_GET['http_status']) && $_GET['http_status'] !== '') ? (int)$_GET['http_status'] : null,
            'route' => $_GET['route'] ?? null,
            'user_id' => (isset($_GET['user_id']) && $_GET['user_id'] !== '') ? (int)$_GET['user_id'] : null,
            'correlation_id' => $_GET['correlation_id'] ?? null,
        ];
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 25;

        $result = $this->logService->getLogs($filters, $page, $pageSize);
        http_response_code(200);
        echo json_encode($result);
    }

    public function show(int $id): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $log = $this->logService->getLogById($id);
        if ($log === null) {
            $this->logWarning(404, 'Log entry not found', ['route' => RequestContext::getRoute()]);
            http_response_code(404);
            echo json_encode(['error' => 'Log not found']);
            return;
        }

        http_response_code(200);
        echo json_encode($log);
    }

    public function filters(): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        http_response_code(200);
        echo json_encode($this->logService->getFilterOptions());
    }

    /**
     * Endpoint centralizado para recibir logs provenientes del navegador.
     */
    public function ingestFrontend(): void
    {
        $raw = file_get_contents('php://input') ?: '';
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON payload']);
            return;
        }

        RequestContext::setRequestPayload($payload);

        $level = strtolower((string)($payload['level'] ?? ''));
        $message = trim((string)($payload['message'] ?? ''));

        if ($message === '' || !in_array($level, self::FRONTEND_LEVELS, true)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid log payload']);
            return;
        }

        $frontendContext = $this->sanitizeFrontendContext($payload['context'] ?? []);
        $url = isset($payload['url']) ? (string)$payload['url'] : RequestContext::getRoute();
        $userAgent = isset($payload['userAgent']) ? (string)$payload['userAgent'] : ($_SERVER['HTTP_USER_AGENT'] ?? 'unknown');
        $userId = (isset($payload['userId']) && $payload['userId'] !== '')
            ? (int)$payload['userId']
            : null;
        $correlationId = isset($payload['correlationId']) ? (string)$payload['correlationId'] : null;

        $decoratedMessage = sprintf(
            '[FRONTEND] [url=%s] [userId=%s] [cid=%s] %s',
            mb_substr($url, 0, 120),
            $userId ?? 'anon',
            $correlationId ?? RequestContext::getCorrelationId(),
            $message
        );

        $context = [
            'origin' => 'frontend',
            'route' => $url,
            'user_id' => $userId,
            'user_agent' => $userAgent,
            'correlation_id' => $correlationId,
            'frontend' => [
                'timestamp' => $payload['timestamp'] ?? null,
                'context' => $frontendContext,
            ],
        ];

        $this->forwardFrontendLog($level, $decoratedMessage, $context);

        http_response_code(204);
    }

    /**
     * @param mixed $context
     * @return array<string,mixed>
     */
    private function sanitizeFrontendContext(mixed $context): array
    {
        if (!is_array($context)) {
            return [];
        }

        $clean = [];
        foreach ($context as $key => $value) {
            if (is_scalar($value) || $value === null) {
                $clean[$key] = $this->truncateScalar($value);
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeFrontendContext($value);
                continue;
            }

            $clean[$key] = $this->truncateScalar((string)$value);
        }

        return $clean;
    }

    private function truncateScalar(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 200 ? mb_substr($value, 0, 200) . '…' : $value;
        }

        return $value;
    }

    private function forwardFrontendLog(string $level, string $message, array $context): void
    {
        switch ($level) {
            case 'debug':
                $this->logService->debug($message, $context);
                break;
            case 'info':
                $this->logService->info($message, $context);
                break;
            case 'warning':
                $this->logService->warning($message, $context);
                break;
            default:
                $this->logService->error($message, $context);
                break;
        }
    }

    private function authorizeAdmin(): ?object
    {
        $token = $this->getAccessTokenFromRequest();

        if ($token === null) {
            $this->logWarning(401, 'Missing token for logs endpoint', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logWarning(401, 'Invalid token for logs endpoint', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);
        if ((strtolower($payload->role ?? '') !== 'admin')) {
            $this->logWarning(403, 'Forbidden access to logs endpoint', ['route' => RequestContext::getRoute()]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }

        return $payload;
    }
}
