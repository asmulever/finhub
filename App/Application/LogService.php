<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\LogRepositoryInterface;
use App\Infrastructure\Config;
use App\Infrastructure\RequestContext;

/**
 * Logger central del sistema: aplica LOG_LEVEL, escribe archivo y persiste en base.
 */
class LogService
{
    private const LEVEL_WEIGHTS = [
        'debug' => 100,
        'info' => 200,
        'warning' => 300,
        'error' => 400,
    ];

    private static ?LogService $instance = null;

    private string $logFilePath;
    private int $threshold;

    public function __construct(
        private readonly LogRepositoryInterface $logRepository,
        ?string $logFilePath = null,
        ?string $logLevel = null
    ) {
        $this->logFilePath = $this->resolveLogFilePath($logFilePath);
        $configured = strtolower($logLevel ?? (string)Config::get('LOG_LEVEL', 'debug'));
        $this->threshold = $this->resolveThreshold($configured);
    }

    public static function registerInstance(LogService $logger): void
    {
        self::$instance = $logger;
    }

    public static function getInstance(): LogService
    {
        if (self::$instance === null) {
            throw new \RuntimeException('LogService has not been initialized.');
        }

        return self::$instance;
    }

    public function debug(string $message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function logException(\Throwable $exception, int $status = 500, array $context = []): void
    {
        $context['exception'] = $exception;
        $context['http_status'] = $context['http_status'] ?? $status;
        $this->error($exception->getMessage(), $context);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getLogs(array $filters, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $result = $this->logRepository->paginate($filters, $page, $pageSize);

        return [
            'data' => $result['data'],
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $result['total'],
                'total_pages' => (int)ceil(($result['total'] ?: 1) / $pageSize),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLogById(int $id): ?array
    {
        return $this->logRepository->findById($id);
    }

    /**
     * @return array{
     *     http_statuses: int[],
     *     levels: string[],
     *     routes: string[]
     * }
     */
    public function getFilterOptions(): array
    {
        return $this->logRepository->getFilterOptions();
    }

    private function log(string $level, string $message, array $context): void
    {
        if (!$this->shouldLog($level)) {
            return;
        }

        $record = $this->buildRecord($level, $this->trimMessage($message), $context);
        $this->writeToFile($record);
        $this->persistRecord($record);
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function buildRecord(string $level, string $message, array $context): array
    {
        $request = $this->getRequestContext();

        $normalizedContext = $this->sanitizeContext($context);
        $exception = $context['exception'] ?? null;
        if ($exception instanceof \Throwable) {
            $normalizedContext['exception_class'] = $exception::class;
            $normalizedContext['exception_message'] = mb_substr($exception->getMessage(), 0, 500);
        }

        return [
            'timestamp' => date('Y-m-d H:i:s'),
            'level' => $level,
            'message' => $message,
            'origin' => $normalizedContext['origin'] ?? 'app',
            'http_status' => (int)($context['http_status'] ?? $context['status'] ?? ($level === 'error' ? 500 : 200)),
            'method' => strtoupper($context['method'] ?? $request['method'] ?? (PHP_SAPI === 'cli' ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET'))),
            'route' => $context['route'] ?? $request['route'] ?? 'cli',
            'request_payload' => $request['request_payload'] ?? null,
            'query_params' => $request['query_params'] ?? null,
            'user_id' => $context['user_id'] ?? $request['user_id'] ?? null,
            'client_ip' => $request['client_ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? $request['user_agent'] ?? null,
            'correlation_id' => $context['correlation_id'] ?? $request['correlation_id'] ?? bin2hex(random_bytes(12)),
            'exception' => $exception instanceof \Throwable ? $exception : null,
            'stack_trace' => $context['stack_trace'] ?? ($exception instanceof \Throwable ? $exception->getTraceAsString() : null),
            'context' => $normalizedContext,
        ];
    }

    /**
     * @param array<string,mixed> $record
     */
    private function writeToFile(array $record): void
    {
        $context = $record['context'];
        unset($context['exception']);
        unset($context['stack_trace']);

        $contextString = '';
        if (!empty($context)) {
            $contextString = '[' . $this->encodeJsonSafe($context) . ']';
        }

        $originSegment = $record['origin'] !== '' ? '[' . strtoupper((string)$record['origin']) . ']' : '';
        $line = sprintf(
            '[%s][%s]%s%s %s',
            $record['timestamp'],
            strtoupper($record['level']),
            $originSegment,
            $contextString,
            $record['message']
        );

        $this->prependLineToFile($line);
    }

    private function prependLineToFile(string $line): void
    {
        $existing = @file_get_contents($this->logFilePath);
        if ($existing === false) {
            $existing = '';
        }

        @file_put_contents(
            $this->logFilePath,
            $line . PHP_EOL . $existing,
            LOCK_EX
        );
    }

    /**
     * @param array<string,mixed> $record
     */
    private function persistRecord(array $record): void
    {
        try {
            $this->logRepository->store([
                'level' => $record['level'],
                'http_status' => $record['http_status'],
                'method' => $record['method'],
                'route' => $record['route'],
                'message' => $record['message'],
                'exception_class' => $record['exception'] instanceof \Throwable ? $record['exception']::class : null,
                'stack_trace' => $record['stack_trace'],
                'request_payload' => $record['request_payload'],
                'query_params' => $record['query_params'],
                'user_id' => $record['user_id'],
                'client_ip' => $record['client_ip'],
                'user_agent' => $record['user_agent'],
                'correlation_id' => $record['correlation_id'],
            ]);
        } catch (\Throwable $e) {
            $fallback = sprintf(
                '[%s][ERROR][LogService] Failed to persist log entry: %s',
                date('Y-m-d H:i:s'),
                $e->getMessage()
            );
            @file_put_contents($this->logFilePath, $fallback . PHP_EOL, FILE_APPEND);
        }
    }

    private function shouldLog(string $level): bool
    {
        return $this->resolveThreshold($level) >= $this->threshold;
    }

    private function resolveThreshold(string $level): int
    {
        return self::LEVEL_WEIGHTS[$level] ?? self::LEVEL_WEIGHTS['debug'];
    }

    private function resolveLogFilePath(?string $configured): string
    {
        if (is_string($configured) && trim($configured) !== '') {
            return $this->preparePath($configured);
        }

        $fromEnv = Config::get('LOG_FILE_PATH');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            return $this->preparePath($fromEnv);
        }

        $defaultDir = dirname(__DIR__, 2) . '/logs';
        if (!is_dir($defaultDir)) {
            @mkdir($defaultDir, 0775, true);
        }

        return $defaultDir . '/finhub.log';
    }

    private function preparePath(string $path): string
    {
        $path = rtrim($path);
        if (is_dir($path)) {
            $targetDir = $path;
        } else {
            $targetDir = dirname($path);
        }

        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0775, true);
        }

        if (is_dir($path)) {
            return rtrim($path, '/') . '/finhub.log';
        }

        return $path;
    }

    private function trimMessage(string $message): string
    {
        return mb_substr($message, 0, 500);
    }

    /**
     * @return array<string,mixed>
     */
    private function getRequestContext(): array
    {
        try {
            return RequestContext::getData();
        } catch (\Throwable) {
            return [
                'method' => PHP_SAPI === 'cli' ? 'CLI' : ($_SERVER['REQUEST_METHOD'] ?? 'GET'),
                'route' => php_sapi_name() === 'cli' ? 'cli' : ($_SERVER['REQUEST_URI'] ?? '/'),
                'query_params' => [],
                'request_payload' => null,
                'user_id' => null,
                'client_ip' => null,
                'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'correlation_id' => bin2hex(random_bytes(12)),
            ];
        }
    }

    /**
     * @param array<string,mixed> $context
     * @return array<string,mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $clean = [];
        foreach ($context as $key => $value) {
            if ($key === 'exception') {
                continue;
            }

            if (is_scalar($value) || $value === null) {
                $clean[$key] = $this->truncateScalar($value);
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitizeContext($value);
                continue;
            }

            if ($value instanceof \Stringable) {
                $clean[$key] = $this->truncateScalar((string)$value);
                continue;
            }

            $clean[$key] = $this->truncateScalar(get_debug_type($value));
        }

        return $clean;
    }

    private function truncateScalar(mixed $value): mixed
    {
        if (is_string($value)) {
            return mb_strlen($value) > 500 ? mb_substr($value, 0, 500) . '…' : $value;
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function encodeJsonSafe(array $data): string
    {
        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '{}';
        }
    }
}
