<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\LogRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlLogRepository implements LogRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function paginate(array $filters, int $page, int $pageSize): array
    {
        $offset = max(0, ($page - 1) * $pageSize);
        [$whereSql, $params] = $this->buildWhereClause($filters);

        $countStmt = $this->db->prepare("SELECT COUNT(*) FROM api_logs {$whereSql}");
        $countStmt->execute($params);
        $total = (int)$countStmt->fetchColumn();

        $stmt = $this->db->prepare("
            SELECT id, created_at, level, http_status, method, route, message, user_id, correlation_id
            FROM api_logs
            {$whereSql}
            ORDER BY created_at DESC, id DESC
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
        $stmt = $this->db->prepare("
            SELECT *
            FROM api_logs
            WHERE id = :id
            LIMIT 1
        ");
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
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
            $conditions[] = 'created_at >= :date_from';
            $params[':date_from'] = $filters['date_from'];
        }

        if (!empty($filters['date_to'])) {
            $conditions[] = 'created_at <= :date_to';
            $params[':date_to'] = $filters['date_to'];
        }

        $whereSql = '';
        if (!empty($conditions)) {
            $whereSql = 'WHERE ' . implode(' AND ', $conditions);
        }

        return [$whereSql, $params];
    }
}
