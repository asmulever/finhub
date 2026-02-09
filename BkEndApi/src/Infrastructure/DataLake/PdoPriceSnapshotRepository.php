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
  provider VARCHAR(32) NOT NULL DEFAULT 'ingestion',
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
  provider VARCHAR(32) NOT NULL DEFAULT 'ingestion',
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
            return ['success' => false, 'reason' => sprintf('Error de fuente: %s', $snapshot['error_code'])];
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
        $stmt = $this->pdo->prepare('SELECT symbol, provider, as_of, payload_json FROM dl_price_snapshots WHERE symbol = :symbol ORDER BY as_of DESC LIMIT 1');
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

    public function fetchCaptureGroups(string $group = 'minute'): array
    {
        $fmt = $this->resolveGroupFormat($group);
        $sql = sprintf(
            "SELECT DATE_FORMAT(as_of, '%s') AS bucket, COUNT(*) AS total, MIN(as_of) AS min_as_of, MAX(as_of) AS max_as_of FROM dl_price_snapshots GROUP BY bucket ORDER BY bucket DESC LIMIT 200",
            $fmt
        );
        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        $groups = [];
        foreach ($rows ?: [] as $row) {
            $groups[] = [
                'bucket' => (string) $row['bucket'],
                'total' => (int) $row['total'],
                'from' => (string) $row['min_as_of'],
                'to' => (string) $row['max_as_of'],
            ];
        }
        return $groups;
    }

    public function fetchCaptures(string $bucket, string $group = 'minute', ?string $symbol = null): array
    {
        $range = $this->buildRange($bucket, $group);
        if ($range === null) {
            return [];
        }
        [$start, $end] = $range;
        $params = [
            ':start' => $start,
            ':end' => $end,
        ];
        $where = 'as_of >= :start AND as_of < :end';
        if ($symbol !== null && $symbol !== '') {
            $where .= ' AND symbol = :symbol';
            $params[':symbol'] = $symbol;
        }
        $sql = sprintf('SELECT symbol, provider, as_of, payload_json FROM dl_price_snapshots WHERE %s ORDER BY as_of DESC', $where);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $captures = [];
        foreach ($rows ?: [] as $row) {
            $payload = $row['payload_json'];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (!is_array($payload)) {
                continue;
            }
            $captures[] = [
                'symbol' => (string) $row['symbol'],
                'provider' => (string) $row['provider'],
                'as_of' => (string) $row['as_of'],
                'payload' => $payload,
            ];
        }
        return $captures;
    }

    private function resolveGroupFormat(string $group): string
    {
        return match ($group) {
            'hour' => '%Y-%m-%d %H:00',
            'date' => '%Y-%m-%d',
            default => '%Y-%m-%d %H:%i',
        };
    }

    private function buildRange(string $bucket, string $group): ?array
    {
        $normalizedGroup = match ($group) {
            'hour' => 'hour',
            'date' => 'date',
            default => 'minute',
        };
        try {
            $start = match ($normalizedGroup) {
                'hour' => new \DateTimeImmutable($bucket . ':00'),
                'date' => new \DateTimeImmutable($bucket . ' 00:00:00'),
                default => new \DateTimeImmutable($bucket . ':00'),
            };
        } catch (\Throwable) {
            return null;
        }
        $end = match ($normalizedGroup) {
            'hour' => $start->modify('+1 hour'),
            'date' => $start->modify('+1 day'),
            default => $start->modify('+1 minute'),
        };
        return [
            $start->format('Y-m-d H:i:s'),
            $end->format('Y-m-d H:i:s'),
        ];
    }
}
