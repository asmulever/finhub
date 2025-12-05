<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\LogRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlLogRepository implements LogRepositoryInterface
{
    private ?PDO $db = null;

    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        [$whereSql, $params] = $this->buildWhereClause($filters);

        $countStmt = $this->getConnection()->prepare("SELECT COUNT(*) FROM api_logs {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->getConnection()->prepare("
            SELECT id, created_at, level, http_status, method, route, message, correlation_id
            FROM api_logs
            {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [],
            'total' => $total,
        ];
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->getConnection()->prepare("
            SELECT *
            FROM api_logs
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function getFilterOptions(): array
    {
        $statusStmt = $this->getConnection()->query('SELECT DISTINCT http_status FROM api_logs ORDER BY http_status');
        $levelStmt = $this->getConnection()->query("SELECT DISTINCT level FROM api_logs ORDER BY FIELD(level, 'error','warning','info','debug'), level");
        $routeStmt = $this->getConnection()->query('SELECT DISTINCT route FROM api_logs ORDER BY route');

        return [
            'http_statuses' => array_values(array_filter(array_map('intval', $statusStmt->fetchAll(PDO::FETCH_COLUMN) ?: []))),
            'levels' => array_values(array_filter($levelStmt->fetchAll(PDO::FETCH_COLUMN) ?: [], fn($v) => $v !== null && $v !== '')),
            'routes' => array_values(array_filter($routeStmt->fetchAll(PDO::FETCH_COLUMN) ?: [], fn($v) => $v !== null && $v !== '')),
        ];
    }

    public function store(array $record): void
    {
        $stmt = $this->getConnection()->prepare("
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
            'level' => $record['level'],
            'http_status' => $record['http_status'],
            'method' => $record['method'],
            'route' => $record['route'],
            'message' => $record['message'],
            'exception_class' => $record['exception_class'],
            'stack_trace' => $record['stack_trace'],
            'request_payload' => $this->encodeJson($record['request_payload']),
            'query_params' => $this->encodeJson($record['query_params']),
            'user_id' => $record['user_id'],
            'client_ip' => $record['client_ip'],
            'user_agent' => $record['user_agent'],
            'correlation_id' => $record['correlation_id'],
        ]);
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{0: string, 1: array<string,mixed>}
     */
    private function buildWhereClause(array $filters): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['level'])) {
            $conditions[] = 'level = :level';
            $params[':level'] = $filters['level'];
        }

        if (!empty($filters['http_status'])) {
            $conditions[] = 'http_status = :status';
            $params[':status'] = (int)$filters['http_status'];
        }

        if (!empty($filters['route'])) {
            $conditions[] = 'route LIKE :route';
            $params[':route'] = '%' . $filters['route'] . '%';
        }

        if (isset($filters['user_id']) && $filters['user_id'] !== '') {
            $conditions[] = 'user_id = :user_id';
            $params[':user_id'] = (int)$filters['user_id'];
        }

        if (!empty($filters['correlation_id'])) {
            $conditions[] = 'correlation_id = :correlation_id';
            $params[':correlation_id'] = $filters['correlation_id'];
        }

        if (!empty($filters['date_from'])) {
            $conditions[] = 'DATE(created_at) >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'DATE(created_at) <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereSql = '';
        if (!empty($conditions)) {
            $whereSql = 'WHERE ' . implode(' AND ', $conditions);
        }

        return [$whereSql, $params];
    }

    private function getConnection(): PDO
    {
        if ($this->db === null) {
            $this->db = DatabaseManager::getConnection();
        }

        return $this->db;
    }

    private function encodeJson(?array $value): ?string
    {
        if ($value === null) {
            return null;
        }

        try {
            return json_encode($value, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }
    }
}
