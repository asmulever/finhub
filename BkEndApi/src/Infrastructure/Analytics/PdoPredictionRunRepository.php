<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Analytics;

use FinHub\Application\Analytics\PredictionRunRepositoryInterface;
use PDO;

/**
 * Infraestructura: gestión de runs de predicción en MySQL.
 */
final class PdoPredictionRunRepository implements PredictionRunRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function startRun(string $scope, ?int $userId): int
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
INSERT INTO instrument_prediction_runs (scope, user_id, status, started_at, created_at)
VALUES (:scope, :user_id, 'running', NOW(6), NOW(6))
SQL
        );
        $stmt->execute([
            'scope' => $scope,
            'user_id' => $userId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function finishRun(int $runId, string $status, array $summary): void
    {
        $stmt = $this->pdo->prepare(
            <<<'SQL'
UPDATE instrument_prediction_runs
SET status = :status,
    finished_at = NOW(6),
    summary_json = :summary
WHERE id = :id
SQL
        );
        $stmt->execute([
            'status' => $status,
            'summary' => json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'id' => $runId,
        ]);
    }

    public function findRunning(string $scope, ?int $userId): ?array
    {
        $sql = <<<'SQL'
SELECT id, scope, user_id, status, started_at, finished_at, summary_json, created_at
FROM instrument_prediction_runs
WHERE status = 'running'
  AND scope = :scope
  AND (:user_id_filter IS NULL OR user_id = :user_id_match)
  AND started_at >= (NOW() - INTERVAL 6 HOUR)
ORDER BY started_at DESC
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'scope' => $scope,
            'user_id_filter' => $userId,
            'user_id_match' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->mapRow($row);
    }

    public function findLatestFinished(string $scope, ?int $userId): ?array
    {
        $sql = <<<'SQL'
SELECT id, scope, user_id, status, started_at, finished_at, summary_json, created_at
FROM instrument_prediction_runs
WHERE status IN ('success','partial')
  AND scope = :scope
  AND (:user_id_filter IS NULL OR user_id = :user_id_match)
ORDER BY finished_at DESC
LIMIT 1
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'scope' => $scope,
            'user_id_filter' => $userId,
            'user_id_match' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->mapRow($row);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function mapRow(array $row): array
    {
        $summary = $row['summary_json'] ?? null;
        if (is_string($summary)) {
            $decoded = json_decode($summary, true);
            $summary = is_array($decoded) ? $decoded : null;
        }

        return [
            'id' => (int) $row['id'],
            'scope' => (string) $row['scope'],
            'user_id' => $row['user_id'] !== null ? (int) $row['user_id'] : null,
            'status' => (string) $row['status'],
            'started_at' => (string) $row['started_at'],
            'finished_at' => $row['finished_at'] ?? null,
            'summary' => $summary,
            'created_at' => (string) $row['created_at'],
        ];
    }
}
