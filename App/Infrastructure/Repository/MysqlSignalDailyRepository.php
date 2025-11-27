<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\SignalDailyRepositoryInterface;
use App\Domain\SignalDaily;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlSignalDailyRepository implements SignalDailyRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function upsertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO fact_signal_daily
                (instrument_id, calendar_id, signal_type, score, signal_label, details)
                VALUES ';
        $placeholders = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            if (!$row instanceof SignalDaily) {
                continue;
            }
            $placeholders[] = "( :instrument_id_{$i}, :calendar_id_{$i}, :signal_type_{$i}, :score_{$i}, :signal_label_{$i}, :details_{$i} )";
            $params["instrument_id_{$i}"] = $row->getInstrumentId();
            $params["calendar_id_{$i}"] = $row->getCalendarId();
            $params["signal_type_{$i}"] = $row->getSignalType();
            $params["score_{$i}"] = $row->getScore();
            $params["signal_label_{$i}"] = $row->getSignalLabel();
            $details = $row->getDetails();
            $params["details_{$i}"] = $details === null ? null : json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $i++;
        }

        if ($placeholders === []) {
            return;
        }

        $sql .= implode(', ', $placeholders)
            .' ON DUPLICATE KEY UPDATE
                score = VALUES(score),
                signal_label = VALUES(signal_label),
                details = VALUES(details)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array
    {
        $sql = 'SELECT fs.*
                FROM fact_signal_daily fs
                JOIN dim_calendar dc ON fs.calendar_id = dc.id
                WHERE fs.instrument_id = :instrument_id
                  AND dc.date BETWEEN :from AND :to
                ORDER BY dc.date ASC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'instrument_id' => $instrumentId,
            'from' => $fromDate,
            'to' => $toDate,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function deleteOlderThanDate(string $cutoffDate): int
    {
        $sql = 'DELETE fs
                FROM fact_signal_daily fs
                JOIN dim_calendar dc ON fs.calendar_id = dc.id
                WHERE dc.date < :cutoff';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cutoff' => $cutoffDate]);

        return $stmt->rowCount();
    }

    private function mapRowToEntity(array $row): SignalDaily
    {
        $details = null;
        if (isset($row['details']) && $row['details'] !== null && $row['details'] !== '') {
            $decoded = json_decode((string)$row['details'], true);
            $details = is_array($decoded) ? $decoded : null;
        }

        return new SignalDaily(
            (int)$row['instrument_id'],
            (int)$row['calendar_id'],
            $row['signal_type'],
            (float)$row['score'],
            $row['signal_label'],
            $details,
            $row['last_updated_at'] ?? null
        );
    }
}
