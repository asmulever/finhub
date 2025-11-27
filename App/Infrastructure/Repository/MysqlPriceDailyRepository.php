<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\PriceDaily;
use App\Domain\Repository\PriceDailyRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlPriceDailyRepository implements PriceDailyRepositoryInterface
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

        $sql = 'INSERT INTO fact_price_daily
                (instrument_id, calendar_id, open, high, low, close, volume, adj_close, source_primary)
                VALUES ';
        $placeholders = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            if (!$row instanceof PriceDaily) {
                continue;
            }
            $placeholders[] = "( :instrument_id_{$i}, :calendar_id_{$i}, :open_{$i}, :high_{$i}, :low_{$i}, :close_{$i}, :volume_{$i}, :adj_close_{$i}, :source_primary_{$i} )";
            $params["instrument_id_{$i}"] = $row->getInstrumentId();
            $params["calendar_id_{$i}"] = $row->getCalendarId();
            $params["open_{$i}"] = $row->getOpen();
            $params["high_{$i}"] = $row->getHigh();
            $params["low_{$i}"] = $row->getLow();
            $params["close_{$i}"] = $row->getClose();
            $params["volume_{$i}"] = $row->getVolume();
            $params["adj_close_{$i}"] = $row->getAdjClose();
            $params["source_primary_{$i}"] = $row->getSourcePrimary();
            $i++;
        }

        if ($placeholders === []) {
            return;
        }

        $sql .= implode(', ', $placeholders)
            .' ON DUPLICATE KEY UPDATE
                open = VALUES(open),
                high = VALUES(high),
                low = VALUES(low),
                close = VALUES(close),
                volume = VALUES(volume),
                adj_close = VALUES(adj_close),
                source_primary = VALUES(source_primary)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array
    {
        $sql = 'SELECT fp.*
                FROM fact_price_daily fp
                JOIN dim_calendar dc ON fp.calendar_id = dc.id
                WHERE fp.instrument_id = :instrument_id
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

    public function findOneByInstrumentAndCalendar(int $instrumentId, int $calendarId): ?PriceDaily
    {
        $sql = 'SELECT * FROM fact_price_daily
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
        $sql = 'DELETE fp
                FROM fact_price_daily fp
                JOIN dim_calendar dc ON fp.calendar_id = dc.id
                WHERE dc.date < :cutoff';

        $stmt = $this->db->prepare($sql);
        $stmt->execute(['cutoff' => $cutoffDate]);

        return $stmt->rowCount();
    }

    private function mapRowToEntity(array $row): PriceDaily
    {
        return new PriceDaily(
            (int)$row['instrument_id'],
            (int)$row['calendar_id'],
            $row['open'] !== null ? (float)$row['open'] : null,
            $row['high'] !== null ? (float)$row['high'] : null,
            $row['low'] !== null ? (float)$row['low'] : null,
            $row['close'] !== null ? (float)$row['close'] : null,
            $row['volume'] !== null ? (int)$row['volume'] : null,
            $row['adj_close'] !== null ? (float)$row['adj_close'] : null,
            $row['source_primary'],
            $row['last_updated_at'] ?? null
        );
    }
}
