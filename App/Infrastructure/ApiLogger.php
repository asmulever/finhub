<?php

declare(strict_types=1);

namespace App\Infrastructure;

use PDO;

class ApiLogger
{
    private static ?ApiLogger $instance = null;
    private Logger $fileLogger;
    private bool $logWarnings;
    private bool $logInfo;

    private function __construct()
    {
        $this->fileLogger = new Logger();
        $this->logWarnings = filter_var(Config::get('LOG_WARNINGS_ENABLED', '1'), FILTER_VALIDATE_BOOL);
        $this->logInfo = filter_var(Config::get('LOG_INFO_ENABLED', '0'), FILTER_VALIDATE_BOOL);
    }

    public static function bootstrap(): ApiLogger
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function getInstance(): ApiLogger
    {
        return self::$instance ?? self::bootstrap();
    }

    public function logException(\Throwable $e, int $status = 500, array $context = []): void
    {
        $message = $context['message'] ?? $e->getMessage();
        $data = array_merge($context, [
            'exception_class' => $e::class,
            'stack_trace' => $e->getTraceAsString(),
        ]);

        $this->log('error', $status, $message, $data);
    }

    public function logWarning(string $message, int $status, array $context = []): void
    {
        if (!$this->logWarnings) {
            return;
        }

        $this->log('warning', $status, $message, $context);
    }

    public function logInfo(string $message, int $status = 200, array $context = []): void
    {
        if (!$this->logInfo) {
            return;
        }

        $this->log('info', $status, $message, $context);
    }

    private function log(string $level, int $status, string $message, array $context = []): void
    {
        $contextData = $this->buildContextData($status, $message, $context, $level);
        $this->writeToDatabase($contextData);
        $line = sprintf('[%s] %s (%s %s)', strtoupper($level), $message, $contextData['method'], $contextData['route']);

        if ($level === 'error') {
            $this->fileLogger->error($line);
        } elseif ($level === 'warning') {
            $this->fileLogger->warning($line);
        } else {
            $this->fileLogger->info($line);
        }
    }

    private function buildContextData(int $status, string $message, array $context, string $level): array
    {
        $request = RequestContext::getData();
        $payload = $context['request_payload'] ?? $request['request_payload'];
        $query = $context['query_params'] ?? $request['query_params'];

        return [
            'level' => $level,
            'http_status' => $status,
            'method' => $request['method'],
            'route' => $context['route'] ?? $request['route'],
            'message' => mb_substr($message, 0, 500),
            'exception_class' => $context['exception_class'] ?? null,
            'stack_trace' => $context['stack_trace'] ?? null,
            'request_payload' => $this->encodeJsonSafe($payload),
            'query_params' => $this->encodeJsonSafe($query),
            'user_id' => $context['user_id'] ?? $request['user_id'],
            'client_ip' => $request['client_ip'],
            'user_agent' => $request['user_agent'],
            'correlation_id' => $request['correlation_id'],
        ];
    }

    private function writeToDatabase(array $data): void
    {
        try {
            $pdo = DatabaseManager::getConnection();
            $stmt = $pdo->prepare("
                INSERT INTO api_logs (
                    level, http_status, method, route, message, exception_class,
                    stack_trace, request_payload, query_params, user_id,
                    client_ip, user_agent, correlation_id
                ) VALUES (
                    :level, :http_status, :method, :route, :message, :exception_class,
                    :stack_trace, :request_payload, :query_params, :user_id,
                    :client_ip, :user_agent, :correlation_id
                )
            ");
            $stmt->execute([
                'level' => $data['level'],
                'http_status' => $data['http_status'],
                'method' => $data['method'],
                'route' => $data['route'],
                'message' => $data['message'],
                'exception_class' => $data['exception_class'],
                'stack_trace' => $data['stack_trace'],
                'request_payload' => $data['request_payload'],
                'query_params' => $data['query_params'],
                'user_id' => $data['user_id'],
                'client_ip' => $data['client_ip'],
                'user_agent' => $data['user_agent'],
                'correlation_id' => $data['correlation_id'],
            ]);
        } catch (\Throwable $e) {
            $this->fileLogger->error('Failed to write API log: ' . $e->getMessage());
        }
    }

    private function encodeJsonSafe(?array $data): ?string
    {
        if ($data === null) {
            return null;
        }

        try {
            return json_encode($data, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }
}
