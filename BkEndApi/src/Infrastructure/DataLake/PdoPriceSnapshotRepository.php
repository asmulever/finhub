<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\DataLake;

use FinHub\Application\DataLake\PriceSnapshotRepositoryInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;
use PDO;

final class PdoPriceSnapshotRepository implements PriceSnapshotRepositoryInterface
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
        $this->pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS dl_price_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(32) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  as_of DATETIME(6) NOT NULL,
  payload_json JSON NOT NULL,
  payload_hash BINARY(32) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  error_code VARCHAR(64) NULL,
  error_msg VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_snapshot (symbol, provider, as_of, payload_hash),
  INDEX idx_symbol_provider_asof (symbol, provider, as_of),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        $this->pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS dl_price_latest (
  symbol VARCHAR(32) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  as_of DATETIME(6) NOT NULL,
  payload_json JSON NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
    }

    public function storeSnapshot(array $snapshot): array
    {
        // No persistir registros con código de error informado
        if (isset($snapshot['error_code']) && $snapshot['error_code'] !== null && $snapshot['error_code'] !== '') {
            return ['success' => false, 'reason' => sprintf('Error del proveedor: %s', $snapshot['error_code'])];
        }

        try {
            $payloadJson = json_encode($snapshot['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $payloadJson, true);
            $asOf = $snapshot['as_of'] instanceof \DateTimeInterface ? $snapshot['as_of']->format('Y-m-d H:i:s.u') : date('Y-m-d H:i:s.u');

            $insert = <<<'SQL'
INSERT IGNORE INTO dl_price_snapshots (symbol, provider, as_of, payload_json, payload_hash, http_status, error_code, error_msg)
VALUES (:symbol, :provider, :as_of, :payload_json, :payload_hash, :http_status, :error_code, :error_msg)
SQL;
            $stmt = $this->pdo->prepare($insert);
            $stmt->execute([
                'symbol' => $snapshot['symbol'],
                'provider' => $snapshot['provider'],
                'as_of' => $asOf,
                'payload_json' => $payloadJson,
                'payload_hash' => $hash,
                'http_status' => $snapshot['http_status'] ?? null,
                'error_code' => $snapshot['error_code'] ?? null,
                'error_msg' => $snapshot['error_msg'] ?? null,
            ]);

            $upsert = <<<'SQL'
INSERT INTO dl_price_latest (symbol, provider, as_of, payload_json)
VALUES (:symbol, :provider, :as_of, :payload_json)
ON DUPLICATE KEY UPDATE
    as_of = IF(VALUES(as_of) > as_of, VALUES(as_of), as_of),
    payload_json = IF(VALUES(as_of) > as_of, VALUES(payload_json), payload_json),
    updated_at = NOW(6)
SQL;
            $uStmt = $this->pdo->prepare($upsert);
            $uStmt->execute([
                'symbol' => $snapshot['symbol'],
                'provider' => $snapshot['provider'],
                'as_of' => $asOf,
                'payload_json' => $payloadJson,
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            $this->logger->error('datalake.store.error', [
                'symbol' => $snapshot['symbol'] ?? '',
                'message' => $e->getMessage(),
            ]);
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    public function fetchLatest(string $symbol): ?array
    {
        $stmt = $this->pdo->prepare('SELECT symbol, provider, as_of, payload_json FROM dl_price_latest WHERE symbol = :symbol LIMIT 1');
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $payload = $row['payload_json'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            return null;
        }
        return [
            'symbol' => (string) $row['symbol'],
            'provider' => (string) $row['provider'],
            'as_of' => (string) $row['as_of'],
            'payload' => $payload,
        ];
    }

    public function fetchSeries(string $symbol, ?\DateTimeImmutable $since = null): array
    {
        $params = [':symbol' => $symbol];
        $where = 'symbol = :symbol';
        if ($since !== null) {
            $where .= ' AND as_of >= :since';
            $params[':since'] = $since->format('Y-m-d H:i:s.u');
        }
        $query = sprintf('SELECT as_of, provider, payload_json FROM dl_price_snapshots WHERE %s ORDER BY as_of ASC', $where);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $snapshots = [];
        foreach ($rows ?: [] as $row) {
            $payload = $row['payload_json'];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (!is_array($payload)) {
                continue;
            }
            $snapshots[] = [
                'as_of' => (string) $row['as_of'],
                'provider' => (string) $row['provider'],
                'payload' => $payload,
            ];
        }
        return $snapshots;
    }
}
