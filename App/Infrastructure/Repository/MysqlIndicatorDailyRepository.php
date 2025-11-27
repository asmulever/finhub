<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\IndicatorDaily;
use App\Domain\Repository\IndicatorDailyRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlIndicatorDailyRepository implements IndicatorDailyRepositoryInterface
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

        $sql = 'INSERT INTO fact_indicator_daily
                (instrument_id, calendar_id, sma_20, sma_50, sma_200, rsi_14, volatility_20)
                VALUES ';
        $placeholders = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            if (!$row instanceof IndicatorDaily) {
                continue;
            }
            $placeholders[] = "( :instrument_id_{$i}, :calendar_id_{$i}, :sma_20_{$i}, :sma_50_{$i}, :sma_200_{$i}, :rsi_14_{$i}, :volatility_20_{$i} )";
            $params["instrument_id_{$i}"] = $row->getInstrumentId();
            $params["calendar_id_{$i}"] = $row->getCalendarId();
            $params["sma_20_{$i}"] = $row->getSma20();
            $params["sma_50_{$i}"] = $row->getSma50();
            $params["sma_200_{$i}"] = $row->getSma200();
            $params["rsi_14_{$i}"] = $row->getRsi14();
            $params["volatility_20_{$i}"] = $row->getVolatility20();
            $i++;
        }

        if ($placeholders === []) {
            return;
        }

        $sql .= implode(', ', $placeholders)
            .' ON DUPLICATE KEY UPDATE
                sma_20 = VALUES(sma_20),
                sma_50 = VALUES(sma_50),
                sma_200 = VALUES(sma_200),
                rsi_14 = VALUES(rsi_14),
                volatility_20 = VALUES(volatility_20)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array
    {
        $sql = 'SELECT fi.*
                FROM fact_indicator_daily fi
                JOIN dim_calendar dc ON fi.calendar_id = dc.id
                WHERE fi.instrument_id = :instrument_id
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

    public function findOneByInstrumentAndCalendar(int $instrumentId, int $calendarId): ?IndicatorDaily
    {
        $sql = 'SELECT * FROM fact_indicator_daily
                WHERE instrument_id = :instrument_id AND calendar_id = :calendar_id
                LIMIT 1';

        $stmt = $this->db->prepare($sql);
        $stmt->execute([
            'instrument_id' => $instrumentId,
            'calendar_id' => $calendarId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function deleteOlderThanDate(string $cutoffDate): int
    {
        $sql = 'DELETE fi
                FROM fact_indicator_daily fi
                JOIN dim_calendar dc ON fi.calendar_id = dc.id
                WHERE dc.date < :cutoff';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cutoff' => $cutoffDate]);

        return $stmt->rowCount();
    }

    private function mapRowToEntity(array $row): IndicatorDaily
    {
        return new IndicatorDaily(
            (int)$row['instrument_id'],
            (int)$row['calendar_id'],
            $row['sma_20'] !== null ? (float)$row['sma_20'] : null,
            $row['sma_50'] !== null ? (float)$row['sma_50'] : null,
            $row['sma_200'] !== null ? (float)$row['sma_200'] : null,
            $row['rsi_14'] !== null ? (float)$row['rsi_14'] : null,
            $row['volatility_20'] !== null ? (float)$row['volatility_20'] : null,
            $row['last_updated_at'] ?? null
        );
    }
}
