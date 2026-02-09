<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Analytics;

use FinHub\Application\Analytics\PredictionMarketRepositoryInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;
use PDO;

/**
 * Repositorio PDO para snapshots de prediction markets.
 */
final class PdoPredictionMarketRepository implements PredictionMarketRepositoryInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function ensureTables(): void
    {
        $sqlSnapshot = <<<SQL
CREATE TABLE IF NOT EXISTS prediction_snapshot (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source VARCHAR(64) NOT NULL,
    as_of DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_source_created (source, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL;
        $sqlItem = <<<SQL
CREATE TABLE IF NOT EXISTS prediction_item (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    stable_id VARCHAR(191) NOT NULL,
    title VARCHAR(255) NOT NULL,
    category VARCHAR(64) DEFAULT NULL,
    url VARCHAR(255) DEFAULT NULL,
    source_timestamp DATETIME DEFAULT NULL,
    UNIQUE KEY uniq_snapshot_item (snapshot_id, stable_id),
    KEY idx_stable_id (stable_id),
    CONSTRAINT fk_pred_item_snapshot FOREIGN KEY (snapshot_id) REFERENCES prediction_snapshot(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL;
        $sqlOutcome = <<<SQL
CREATE TABLE IF NOT EXISTS prediction_outcome (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(191) NOT NULL,
    probability DECIMAL(6,4) NOT NULL,
    price DECIMAL(12,6) DEFAULT NULL,
    UNIQUE KEY uniq_item_outcome (item_id, name),
    KEY idx_item (item_id),
    CONSTRAINT fk_pred_outcome_item FOREIGN KEY (item_id) REFERENCES prediction_item(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL;

        $this->pdo->exec($sqlSnapshot);
        $this->pdo->exec($sqlItem);
        $this->pdo->exec($sqlOutcome);
    }

    public function storeSnapshot(string $source, string $asOf, array $items): int
    {
        $this->ensureTables();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO prediction_snapshot (source, as_of, created_at) VALUES (:source, :as_of, :created_at)');
            $stmt->execute([
                'source' => $source,
                'as_of' => $this->toDateTime($asOf),
                'created_at' => $now,
            ]);
            $snapshotId = (int) $this->pdo->lastInsertId();

            $stmtItem = $this->pdo->prepare('INSERT INTO prediction_item (snapshot_id, stable_id, title, category, url, source_timestamp) VALUES (:snapshot_id, :stable_id, :title, :category, :url, :source_timestamp)');
            $stmtOutcome = $this->pdo->prepare('INSERT INTO prediction_outcome (item_id, name, probability, price) VALUES (:item_id, :name, :probability, :price)');

            foreach ($items as $item) {
                $stableId = substr(trim((string) ($item['id'] ?? '')), 0, 191);
                $title = substr(trim((string) ($item['title'] ?? $stableId)), 0, 255);
                $category = $item['category'] !== null ? substr(trim((string) $item['category']), 0, 64) : null;
                $url = $item['url'] !== null ? substr(trim((string) $item['url']), 0, 255) : null;
                $sourceTs = $this->nullableDateTime($item['source_timestamp'] ?? null);

                $stmtItem->execute([
                    'snapshot_id' => $snapshotId,
                    'stable_id' => $stableId,
                    'title' => $title,
                    'category' => $category,
                    'url' => $url,
                    'source_timestamp' => $sourceTs,
                ]);
                $itemId = (int) $this->pdo->lastInsertId();

                foreach ($item['outcomes'] ?? [] as $outcome) {
                    if (!is_array($outcome)) {
                        continue;
                    }
                    $name = substr(trim((string) ($outcome['name'] ?? '')), 0, 191);
                    if ($name === '') {
                        continue;
                    }
                    $prob = isset($outcome['probability']) ? (float) $outcome['probability'] : null;
                    if ($prob === null) {
                        continue;
                    }
                    $price = isset($outcome['price']) ? $this->nullableFloat($outcome['price']) : null;
                    $stmtOutcome->execute([
                        'item_id' => $itemId,
                        'name' => $name,
                        'probability' => $prob,
                        'price' => $price,
                    ]);
                }
            }

            $this->pdo->commit();
            return $snapshotId;
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->logger->error('prediction.snapshot.store_failed', [
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function findLatestSnapshot(string $source): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, source, as_of, created_at FROM prediction_snapshot WHERE source = :source ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['source' => $source]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $items = $this->fetchItems((int) $row['id']);
        return [
            'id' => (int) $row['id'],
            'source' => $row['source'],
            'as_of' => $this->fromDateTime($row['as_of']),
            'created_at' => $this->fromDateTime($row['created_at']),
            'items' => $items,
        ];
    }

    public function findPreviousSnapshot(string $source, int $excludeSnapshotId): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, source, as_of, created_at FROM prediction_snapshot WHERE source = :source AND id <> :exclude ORDER BY created_at DESC LIMIT 1');
        $stmt->execute([
            'source' => $source,
            'exclude' => $excludeSnapshotId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $items = $this->fetchItems((int) $row['id']);
        return [
            'id' => (int) $row['id'],
            'source' => $row['source'],
            'as_of' => $this->fromDateTime($row['as_of']),
            'created_at' => $this->fromDateTime($row['created_at']),
            'items' => $items,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchItems(int $snapshotId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM prediction_item WHERE snapshot_id = :sid ORDER BY id ASC');
        $stmt->execute(['sid' => $snapshotId]);
        $items = [];
        while ($item = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $itemId = (int) $item['id'];
            $items[] = [
                'id' => $item['stable_id'],
                'title' => $item['title'],
                'category' => $item['category'],
                'url' => $item['url'],
                'source_timestamp' => $this->fromDateTime($item['source_timestamp']),
                'outcomes' => $this->fetchOutcomes($itemId),
            ];
        }
        return $items;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchOutcomes(int $itemId): array
    {
        $stmt = $this->pdo->prepare('SELECT name, probability, price FROM prediction_outcome WHERE item_id = :iid ORDER BY id ASC');
        $stmt->execute(['iid' => $itemId]);
        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'name' => $row['name'],
                'probability' => (float) $row['probability'],
                'price' => $row['price'] !== null ? (float) $row['price'] : null,
            ];
        }
        return $rows;
    }

    private function toDateTime(string $value): string
    {
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable $e) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
    }

    private function fromDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function nullableFloat($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function nullableDateTime($value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        return $this->toDateTime((string) $value);
    }
}
