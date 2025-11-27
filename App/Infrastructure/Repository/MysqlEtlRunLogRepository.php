<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\EtlRun;
use App\Domain\Repository\EtlRunLogRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use DateTimeImmutable;
use PDO;

class MysqlEtlRunLogRepository implements EtlRunLogRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function startRun(string $jobName): EtlRun
    {
        $startedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'INSERT INTO etl_run_log (job_name, started_at, status, rows_affected)
             VALUES (:job_name, :started_at, :status, :rows_affected)'
        );
        $stmt->execute([
            'job_name' => $jobName,
            'started_at' => $startedAt,
            'status' => 'OK',
            'rows_affected' => 0,
        ]);

        $id = (int)$this->db->lastInsertId();

        return new EtlRun(
            $id,
            $jobName,
            $startedAt,
            null,
            'OK',
            0,
            null
        );
    }

    public function finishRun(EtlRun $run, string $status, int $rowsAffected, ?string $message = null): EtlRun
    {
        $finishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->db->prepare(
            'UPDATE etl_run_log
             SET finished_at = :finished_at,
                 status = :status,
                 rows_affected = :rows_affected,
                 message = :message
             WHERE id = :id'
        );

        $stmt->execute([
            'finished_at' => $finishedAt,
            'status' => $status,
            'rows_affected' => $rowsAffected,
            'message' => $message,
            'id' => $run->getId(),
        ]);

        return new EtlRun(
            $run->getId(),
            $run->getJobName(),
            $run->getStartedAt(),
            $finishedAt,
            $status,
            $rowsAffected,
            $message,
            $run->getCreatedAt()
        );
    }

    public function findRecentByJob(string $jobName, int $limit = 50): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM etl_run_log
             WHERE job_name = :job_name
             ORDER BY started_at DESC
             LIMIT :limit'
        );

        $stmt->bindValue('job_name', $jobName);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    private function mapRowToEntity(array $row): EtlRun
    {
        return new EtlRun(
            (int)$row['id'],
            $row['job_name'],
            $row['started_at'],
            $row['finished_at'] ?? null,
            $row['status'],
            (int)$row['rows_affected'],
            $row['message'] ?? null,
            $row['created_at'] ?? null
        );
    }
}
