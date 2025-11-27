<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\InstrumentSourceMap;
use App\Domain\Repository\InstrumentSourceMapRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlInstrumentSourceMapRepository implements InstrumentSourceMapRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function findBySourceAndSymbol(string $source, string $sourceSymbol): ?InstrumentSourceMap
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM instrument_source_map WHERE source = :source AND source_symbol = :source_symbol LIMIT 1'
        );
        $stmt->execute([
            'source' => $source,
            'source_symbol' => $sourceSymbol,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function resolveInstrumentId(string $source, string $sourceSymbol): ?int
    {
        $stmt = $this->db->prepare(
            'SELECT instrument_id FROM instrument_source_map WHERE source = :source AND source_symbol = :source_symbol LIMIT 1'
        );
        $stmt->execute([
            'source' => $source,
            'source_symbol' => $sourceSymbol,
        ]);

        $value = $stmt->fetchColumn();
        return $value === false ? null : (int)$value;
    }

    public function save(InstrumentSourceMap $map): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO instrument_source_map
                (instrument_id, source, source_symbol, extra)
             VALUES
                (:instrument_id, :source, :source_symbol, :extra)
             ON DUPLICATE KEY UPDATE
                instrument_id = VALUES(instrument_id),
                extra = VALUES(extra)'
        );

        $extra = $map->getExtra();
        $stmt->execute([
            'instrument_id' => $map->getInstrumentId(),
            'source' => $map->getSource(),
            'source_symbol' => $map->getSourceSymbol(),
            'extra' => $extra === null ? null : json_encode($extra, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $id = $map->getId();
        if ($id !== null) {
            return $id;
        }

        $lastId = (int)$this->db->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }

        $existing = $this->findBySourceAndSymbol($map->getSource(), $map->getSourceSymbol());
        return $existing?->getId() ?? 0;
    }

    public function findAllBySource(string $source): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM instrument_source_map WHERE source = :source ORDER BY id ASC'
        );
        $stmt->execute(['source' => $source]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    private function mapRowToEntity(array $row): InstrumentSourceMap
    {
        $extra = null;
        if (isset($row['extra']) && $row['extra'] !== null && $row['extra'] !== '') {
            $decoded = json_decode((string)$row['extra'], true);
            $extra = is_array($decoded) ? $decoded : null;
        }

        return new InstrumentSourceMap(
            (int)$row['id'],
            (int)$row['instrument_id'],
            $row['source'],
            $row['source_symbol'],
            $extra,
            $row['created_at'] ?? null
        );
    }
}
