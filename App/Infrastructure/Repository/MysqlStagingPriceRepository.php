<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\StagingPriceRepositoryInterface;
use App\Domain\StagingPriceRaw;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlStagingPriceRepository implements StagingPriceRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function insertBatch(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $sql = 'INSERT INTO staging_price_raw
                (source, source_symbol, date, open, high, low, close, volume, raw_payload)
                VALUES ';
        $placeholders = [];
        $params = [];
        $i = 0;

        foreach ($rows as $row) {
            if (!$row instanceof StagingPriceRaw) {
                continue;
            }
            $placeholders[] = "( :source_{$i}, :source_symbol_{$i}, :date_{$i}, :open_{$i}, :high_{$i}, :low_{$i}, :close_{$i}, :volume_{$i}, :raw_payload_{$i} )";
            $params["source_{$i}"] = $row->getSource();
            $params["source_symbol_{$i}"] = $row->getSourceSymbol();
            $params["date_{$i}"] = $row->getDate();
            $params["open_{$i}"] = $row->getOpen();
            $params["high_{$i}"] = $row->getHigh();
            $params["low_{$i}"] = $row->getLow();
            $params["close_{$i}"] = $row->getClose();
            $params["volume_{$i}"] = $row->getVolume();
            $params["raw_payload_{$i}"] = $row->getRawPayload();
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
                raw_payload = VALUES(raw_payload)';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
    }

    public function findByDateRange(?string $fromDate, ?string $toDate): array
    {
        $conditions = [];
        $params = [];

        if ($fromDate !== null) {
            $conditions[] = 'date >= :from';
            $params['from'] = $fromDate;
        }
        if ($toDate !== null) {
            $conditions[] = 'date <= :to';
            $params['to'] = $toDate;
        }

        $where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM staging_price_raw {$where} ORDER BY date ASC, source, source_symbol";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function findByIngestedAtRange(?string $from, ?string $to): array
    {
        $conditions = [];
        $params = [];

        if ($from !== null) {
            $conditions[] = 'ingested_at >= :from';
            $params['from'] = $from;
        }
        if ($to !== null) {
            $conditions[] = 'ingested_at <= :to';
            $params['to'] = $to;
        }

        $where = $conditions ? 'WHERE '.implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM staging_price_raw {$where} ORDER BY ingested_at ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function deleteOlderThan(string $cutoffDate): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM staging_price_raw WHERE date < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoffDate]);
        return $stmt->rowCount();
    }

    private function mapRowToEntity(array $row): StagingPriceRaw
    {
        return new StagingPriceRaw(
            isset($row['id']) ? (int)$row['id'] : null,
            $row['source'],
            $row['source_symbol'],
            $row['date'],
            $row['open'] !== null ? (float)$row['open'] : null,
            $row['high'] !== null ? (float)$row['high'] : null,
            $row['low'] !== null ? (float)$row['low'] : null,
            $row['close'] !== null ? (float)$row['close'] : null,
            $row['volume'] !== null ? (int)$row['volume'] : null,
            $row['raw_payload'] ?? null,
            $row['ingested_at'] ?? null
        );
    }
}
