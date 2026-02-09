<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Radar;

use FinHub\Application\Radar\RadarRepositoryInterface;
use PDO;

final class PdoRadarRepository implements RadarRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureTables(): void
    {
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS radar_snapshot (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    as_of DATETIME NOT NULL,
    model_version VARCHAR(64) NOT NULL,
    horizon VARCHAR(16) NOT NULL,
    risk_profile VARCHAR(32) NOT NULL,
    constraints_json JSON NULL,
    created_at DATETIME NOT NULL,
    KEY idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL);
        $this->pdo->exec(<<<SQL
CREATE TABLE IF NOT EXISTS radar_signal (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    snapshot_id BIGINT UNSIGNED NOT NULL,
    symbol VARCHAR(32) NOT NULL,
    action VARCHAR(16) NOT NULL,
    regime VARCHAR(16) NOT NULL,
    confidence DECIMAL(4,2) NOT NULL,
    drivers_json JSON NULL,
    price_last DECIMAL(18,6) NULL,
    horizon VARCHAR(16) NOT NULL,
    risk_profile VARCHAR(32) NOT NULL,
    data_freshness DATETIME NULL,
    model_version VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_snapshot_symbol (snapshot_id, symbol),
    CONSTRAINT fk_radar_signal_snapshot FOREIGN KEY (snapshot_id) REFERENCES radar_snapshot(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE utf8mb4_unicode_ci;
SQL);
    }

    public function storeSnapshot(array $snapshot): int
    {
        $this->ensureTables();
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare('INSERT INTO radar_snapshot (as_of, model_version, horizon, risk_profile, constraints_json, created_at) VALUES (:as_of, :model_version, :horizon, :risk_profile, :constraints_json, :created_at)');
        $stmt->execute([
            'as_of' => $this->toDateTime($snapshot['as_of'] ?? $now),
            'model_version' => (string) ($snapshot['model_version'] ?? 'radar-heuristic-1'),
            'horizon' => (string) ($snapshot['horizon'] ?? ''),
            'risk_profile' => (string) ($snapshot['risk_profile'] ?? ''),
            'constraints_json' => json_encode($snapshot['constraints'] ?? [], JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
        ]);
        $snapshotId = (int) $this->pdo->lastInsertId();

        $signalStmt = $this->pdo->prepare('INSERT INTO radar_signal (snapshot_id, symbol, action, regime, confidence, drivers_json, price_last, horizon, risk_profile, data_freshness, model_version, created_at) VALUES (:snapshot_id, :symbol, :action, :regime, :confidence, :drivers_json, :price_last, :horizon, :risk_profile, :data_freshness, :model_version, :created_at)');
        foreach ($snapshot['signals'] ?? [] as $sig) {
            $signalStmt->execute([
                'snapshot_id' => $snapshotId,
                'symbol' => substr((string) ($sig['symbol'] ?? ''), 0, 32),
                'action' => substr((string) ($sig['action'] ?? ''), 0, 16),
                'regime' => substr((string) ($sig['regime'] ?? ''), 0, 16),
                'confidence' => (float) ($sig['confidence'] ?? 0),
                'drivers_json' => json_encode($sig['drivers'] ?? [], JSON_UNESCAPED_UNICODE),
                'price_last' => isset($sig['price_last']) ? (float) $sig['price_last'] : null,
                'horizon' => substr((string) ($sig['horizon'] ?? ''), 0, 16),
                'risk_profile' => substr((string) ($sig['risk_profile'] ?? ''), 0, 32),
                'data_freshness' => $this->toDateTime($sig['data_freshness'] ?? null),
                'model_version' => substr((string) ($sig['model_version'] ?? $snapshot['model_version'] ?? 'radar-heuristic-1'), 0, 64),
                'created_at' => $now,
            ]);
        }

        return $snapshotId;
    }

    public function findSnapshot(int $id): ?array
    {
        $this->ensureTables();
        $stmt = $this->pdo->prepare('SELECT * FROM radar_snapshot WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        $signals = $this->fetchSignals($id);
        return [
            'id' => (int) $row['id'],
            'as_of' => $this->fromDateTime($row['as_of']),
            'model_version' => $row['model_version'],
            'horizon' => $row['horizon'],
            'risk_profile' => $row['risk_profile'],
            'constraints' => json_decode((string) $row['constraints_json'], true) ?? [],
            'created_at' => $this->fromDateTime($row['created_at']),
            'signals' => $signals,
        ];
    }

    private function fetchSignals(int $snapshotId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM radar_signal WHERE snapshot_id = :sid ORDER BY id ASC');
        $stmt->execute(['sid' => $snapshotId]);
        $rows = [];
        while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $rows[] = [
                'symbol' => $r['symbol'],
                'action' => $r['action'],
                'regime' => $r['regime'],
                'confidence' => (float) $r['confidence'],
                'drivers' => json_decode((string) $r['drivers_json'], true) ?? [],
                'price_last' => $r['price_last'] !== null ? (float) $r['price_last'] : null,
                'horizon' => $r['horizon'],
                'risk_profile' => $r['risk_profile'],
                'data_freshness' => $this->fromDateTime($r['data_freshness']),
                'model_version' => $r['model_version'],
                'created_at' => $this->fromDateTime($r['created_at']),
            ];
        }
        return $rows;
    }

    private function toDateTime(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return null;
        }
    }

    private function fromDateTime(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($value))->format(\DateTimeInterface::ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
