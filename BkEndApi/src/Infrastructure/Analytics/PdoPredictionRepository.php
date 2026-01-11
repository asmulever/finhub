<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Analytics;

use FinHub\Application\Analytics\PredictionRepositoryInterface;
use PDO;

/**
 * Infraestructura: persistencia de predicciones por instrumento.
 */
final class PdoPredictionRepository implements PredictionRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function replacePredictions(int $runId, int $userId, array $items): void
    {
        $this->pdo->beginTransaction();
        try {
            $delete = $this->pdo->prepare('DELETE FROM instrument_predictions WHERE run_id = :run_id AND user_id = :user_id');
            $delete->execute(['run_id' => $runId, 'user_id' => $userId]);

            $insert = $this->pdo->prepare(
                <<<'SQL'
INSERT INTO instrument_predictions (run_id, user_id, symbol, horizon_days, prediction, confidence, created_at)
VALUES (:run_id, :user_id, :symbol, :horizon, :prediction, :confidence, :created_at)
SQL
            );
            foreach ($items as $item) {
                $insert->execute([
                    'run_id' => $runId,
                    'user_id' => $userId,
                    'symbol' => strtoupper($item['symbol']),
                    'horizon' => (int) $item['horizon'],
                    'prediction' => $item['prediction'],
                    'confidence' => $item['confidence'],
                    'created_at' => $item['created_at'] ?? date('Y-m-d H:i:s'),
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    public function findLatestByUser(int $userId): array
    {
        $runSql = <<<'SQL'
SELECT id FROM instrument_prediction_runs
WHERE scope = 'user' AND status IN ('success','partial') AND user_id = :user_id
ORDER BY finished_at DESC
LIMIT 1
SQL;
        $runStmt = $this->pdo->prepare($runSql);
        $runStmt->execute(['user_id' => $userId]);
        $runId = $runStmt->fetchColumn();
        if ($runId === false) {
            return [];
        }

        $sql = <<<'SQL'
SELECT symbol, horizon_days, prediction, confidence, created_at
FROM instrument_predictions
WHERE user_id = :user_id AND run_id = :run_id
ORDER BY symbol ASC, horizon_days ASC
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $userId, 'run_id' => (int) $runId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $items[] = [
                'symbol' => (string) $row['symbol'],
                'horizon' => (int) $row['horizon_days'],
                'prediction' => (string) $row['prediction'],
                'confidence' => $row['confidence'] !== null ? (float) $row['confidence'] : null,
                'created_at' => $row['created_at'] ?? null,
            ];
        }
        return $items;
    }
}
