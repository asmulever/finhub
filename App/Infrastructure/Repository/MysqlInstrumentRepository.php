<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Instrument;
use App\Domain\Repository\InstrumentRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlInstrumentRepository implements InstrumentRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function findById(int $id): ?Instrument
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM dim_instrument WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function findBySymbol(string $symbol): ?Instrument
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM dim_instrument WHERE symbol = :symbol LIMIT 1'
        );
        $stmt->execute(['symbol' => $symbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function findBySourceAndSymbol(string $source, string $sourceSymbol): ?Instrument
    {
        $stmt = $this->db->prepare(
            'SELECT di.*
             FROM dim_instrument di
             JOIN instrument_source_map ism ON di.id = ism.instrument_id
             WHERE ism.source = :source AND ism.source_symbol = :source_symbol
             LIMIT 1'
        );
        $stmt->execute([
            'source' => $source,
            'source_symbol' => $sourceSymbol,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function save(Instrument $instrument): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO dim_instrument
                (symbol, name, type, region, currency, is_active, is_local, is_cedear)
             VALUES
                (:symbol, :name, :type, :region, :currency, :is_active, :is_local, :is_cedear)
             ON DUPLICATE KEY UPDATE
                name = VALUES(name),
                type = VALUES(type),
                region = VALUES(region),
                currency = VALUES(currency),
                is_active = VALUES(is_active),
                is_local = VALUES(is_local),
                is_cedear = VALUES(is_cedear)'
        );

        $stmt->execute([
            'symbol' => $instrument->getSymbol(),
            'name' => $instrument->getName(),
            'type' => $instrument->getType(),
            'region' => $instrument->getRegion(),
            'currency' => $instrument->getCurrency(),
            'is_active' => $instrument->isActive() ? 1 : 0,
            'is_local' => $instrument->isLocal() ? 1 : 0,
            'is_cedear' => $instrument->isCedear() ? 1 : 0,
        ]);

        $id = $instrument->getId();
        if ($id !== null) {
            return $id;
        }

        $lastId = (int)$this->db->lastInsertId();
        if ($lastId > 0) {
            return $lastId;
        }

        $row = $this->findBySymbol($instrument->getSymbol());
        return $row?->getId() ?? 0;
    }

    public function findActive(int $limit, int $offset = 0): array
    {
        $limit = max(1, $limit);
        $offset = max(0, $offset);

        $stmt = $this->db->prepare(
            'SELECT * FROM dim_instrument
             WHERE is_active = 1
             ORDER BY id ASC
             LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    private function mapRowToEntity(array $row): Instrument
    {
        return new Instrument(
            (int)$row['id'],
            $row['symbol'],
            $row['name'],
            $row['type'],
            $row['region'],
            $row['currency'],
            (bool)$row['is_active'],
            (bool)($row['is_local'] ?? 0),
            (bool)($row['is_cedear'] ?? 0),
            $row['created_at'] ?? null,
            $row['updated_at'] ?? null
        );
    }
}
