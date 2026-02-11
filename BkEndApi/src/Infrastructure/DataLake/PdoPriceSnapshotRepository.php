<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\DataLake;

use FinHub\Application\DataLake\PriceSnapshotRepositoryInterface;
use PDO;

/**
 * Repositorio de snapshots de precios (diarios) persistidos 1 año.
 */
final class PdoPriceSnapshotRepository implements PriceSnapshotRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTables(): void
    {
        $tables = $this->pdo->query("SHOW TABLES LIKE 'r2_price_snapshot'")->fetchAll(PDO::FETCH_NUM);
        if (empty($tables)) {
            throw new \RuntimeException('Tabla r2_price_snapshot no existe. Ejecute scripts/r2lite_schema.sql');
        }
    }

    public function storeSnapshot(array $snapshot): array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare(
            'INSERT IGNORE INTO r2_price_snapshot (symbol, category, provider, as_of, payload, http_status, error_code, error_msg, created_at)
             VALUES (:symbol, :category, :provider, :as_of, :payload, :http_status, :error_code, :error_msg, :created_at)'
        );
        $createdAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt->execute([
            'symbol' => strtoupper((string) $snapshot['symbol']),
            'category' => (string) $snapshot['category'],
            'provider' => (string) $snapshot['provider'],
            'as_of' => $this->toDateTime($snapshot['as_of'] ?? $createdAt),
            'payload' => json_encode($snapshot['payload'] ?? [], JSON_UNESCAPED_UNICODE),
            'http_status' => $snapshot['http_status'] ?? null,
            'error_code' => $snapshot['error_code'] ?? null,
            'error_msg' => $snapshot['error_msg'] ?? null,
            'created_at' => $createdAt,
        ]);
        return ['success' => true];
    }

    public function fetchLatest(string $symbol): ?array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare(
            'SELECT * FROM r2_price_snapshot WHERE symbol = :symbol ORDER BY as_of DESC LIMIT 1'
        );
        $stmt->execute(['symbol' => strtoupper($symbol)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchSeries(string $symbol, ?\DateTimeImmutable $since = null): array
    {
        $this->ensureTables();
        $sql = 'SELECT * FROM r2_price_snapshot WHERE symbol = :symbol';
        $params = ['symbol' => strtoupper($symbol)];
        if ($since !== null) {
            $sql .= ' AND as_of >= :since';
            $params['since'] = $since->format('Y-m-d H:i:s');
        }
        $sql .= ' ORDER BY as_of ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function fetchCaptureGroups(string $group = 'minute'): array
    {
        return [];
    }

    public function fetchCaptures(string $bucket, string $group = 'minute', ?string $symbol = null): array
    {
        return [];
    }

    public function purgeOlderThan(\DateTimeImmutable $threshold): int
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare('DELETE FROM r2_price_snapshot WHERE created_at < :threshold');
        $stmt->execute(['threshold' => $threshold->format('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    private function toDateTime(string|\DateTimeInterface $value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        }
    }
}
