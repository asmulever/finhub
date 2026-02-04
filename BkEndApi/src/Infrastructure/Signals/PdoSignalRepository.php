<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Signals;

use FinHub\Application\Signals\SignalRepositoryInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;
use PDO;

/**
 * Repositorio de señales en MySQL.
 */
final class PdoSignalRepository implements SignalRepositoryInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function findLatest(?array $symbols = null): array
    {
        $params = [];
        $where = '';
        if (!empty($symbols)) {
            $placeholders = implode(',', array_fill(0, count($symbols), '?'));
            $where = "WHERE symbol IN ($placeholders)";
            $params = $symbols;
        }
        $sql = "SELECT * FROM signals {$where} ORDER BY symbol, as_of DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $latest = [];
        foreach ($rows as $row) {
            $key = strtoupper((string) ($row['symbol'] ?? ''));
            if ($key === '' || isset($latest[$key])) {
                continue;
            }
            if (isset($row['rationale_tags']) && is_string($row['rationale_tags'])) {
                $row['rationale_tags'] = json_decode($row['rationale_tags'], true);
            }
            if (isset($row['rationale_json']) && is_string($row['rationale_json'])) {
                $row['rationale_json'] = json_decode($row['rationale_json'], true);
            }
            if (isset($row['series_json']) && is_string($row['series_json'])) {
                $row['series_json'] = json_decode($row['series_json'], true);
            }
            $latest[$key] = $row;
        }
        return array_values($latest);
    }

    public function saveSignals(array $signals): void
    {
        if (empty($signals)) {
            return;
        }
        $sql = "INSERT INTO signals (
            symbol, especie, name, exchange, currency, as_of, action, confidence, horizon_days, signal_strength,
            price_last, entry_reference, exp_return_pct, exp_return_amt, range_p10_pct, range_p50_pct, range_p90_pct,
            range_p10_amt, range_p90_amt, volatility_atr, stop_price, take_price, stop_distance_pct, take_distance_pct,
            risk_reward, trend_state, momentum_state, regime, rationale_short, rationale_tags, rationale_json,
            data_quality, data_points_used, costs_included, backtest_ref, series_json, created_at, updated_at
        ) VALUES (
            :symbol, :especie, :name, :exchange, :currency, :as_of, :action, :confidence, :horizon_days, :signal_strength,
            :price_last, :entry_reference, :exp_return_pct, :exp_return_amt, :range_p10_pct, :range_p50_pct, :range_p90_pct,
            :range_p10_amt, :range_p90_amt, :volatility_atr, :stop_price, :take_price, :stop_distance_pct, :take_distance_pct,
            :risk_reward, :trend_state, :momentum_state, :regime, :rationale_short, :rationale_tags, :rationale_json,
            :data_quality, :data_points_used, :costs_included, :backtest_ref, :series_json, NOW(), NOW()
        )";
        $stmt = $this->pdo->prepare($sql);
        foreach ($signals as $signal) {
            try {
                $stmt->execute([
                    ':symbol' => $signal['symbol'] ?? null,
                    ':especie' => $signal['especie'] ?? null,
                    ':name' => $signal['name'] ?? null,
                    ':exchange' => $signal['exchange'] ?? null,
                    ':currency' => $signal['currency'] ?? null,
                    ':as_of' => $signal['as_of'] ?? null,
                    ':action' => $signal['action'] ?? null,
                    ':confidence' => $signal['confidence'] ?? null,
                    ':horizon_days' => $signal['horizon_days'] ?? null,
                    ':signal_strength' => $signal['signal_strength'] ?? null,
                    ':price_last' => $signal['price_last'] ?? null,
                    ':entry_reference' => $signal['entry_reference'] ?? null,
                    ':exp_return_pct' => $signal['exp_return_pct'] ?? null,
                    ':exp_return_amt' => $signal['exp_return_amt'] ?? null,
                    ':range_p10_pct' => $signal['range_p10_pct'] ?? null,
                    ':range_p50_pct' => $signal['range_p50_pct'] ?? null,
                    ':range_p90_pct' => $signal['range_p90_pct'] ?? null,
                    ':range_p10_amt' => $signal['range_p10_amt'] ?? null,
                    ':range_p90_amt' => $signal['range_p90_amt'] ?? null,
                    ':volatility_atr' => $signal['volatility_atr'] ?? null,
                    ':stop_price' => $signal['stop_price'] ?? null,
                    ':take_price' => $signal['take_price'] ?? null,
                    ':stop_distance_pct' => $signal['stop_distance_pct'] ?? null,
                    ':take_distance_pct' => $signal['take_distance_pct'] ?? null,
                    ':risk_reward' => $signal['risk_reward'] ?? null,
                    ':trend_state' => $signal['trend_state'] ?? null,
                    ':momentum_state' => $signal['momentum_state'] ?? null,
                    ':regime' => $signal['regime'] ?? null,
                    ':rationale_short' => $signal['rationale_short'] ?? null,
                    ':rationale_tags' => isset($signal['rationale_tags']) ? json_encode($signal['rationale_tags']) : null,
                    ':rationale_json' => isset($signal['rationale_json']) ? json_encode($signal['rationale_json']) : null,
                    ':data_quality' => $signal['data_quality'] ?? 'OK',
                    ':data_points_used' => $signal['data_points_used'] ?? null,
                    ':costs_included' => isset($signal['costs_included']) ? (int) (bool) $signal['costs_included'] : 0,
                    ':backtest_ref' => $signal['backtest_ref'] ?? null,
                    ':series_json' => isset($signal['series_json']) ? json_encode($signal['series_json']) : null,
                ]);
            } catch (\Throwable $e) {
                $this->logger->info('signals.save.failed', [
                    'symbol' => $signal['symbol'] ?? null,
                    'message' => $e->getMessage(),
                ]);
            }
        }
    }

    public function deleteBySymbols(array $symbols): void
    {
        $normalized = array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $symbols), static fn ($s) => $s !== ''));
        if (empty($normalized)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = "DELETE FROM signals WHERE symbol IN ($placeholders)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($normalized);
        } catch (\Throwable $e) {
            $this->logger->info('signals.delete.failed', [
                'symbols' => $normalized,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function deleteOlderThan(\DateTimeImmutable $threshold): void
    {
        $sql = "DELETE FROM signals WHERE as_of < ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$threshold->format('Y-m-d H:i:s')]);
    }

    public function attachBacktestRef(array $symbols, int $backtestId): void
    {
        $normalized = array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $symbols), static fn ($s) => $s !== ''));
        if (empty($normalized)) {
            return;
        }
        $placeholders = implode(',', array_fill(0, count($normalized), '?'));
        $sql = "UPDATE signals SET backtest_ref = ? WHERE symbol IN ($placeholders)";
        try {
            $stmt = $this->pdo->prepare($sql);
            $params = array_merge([$backtestId], $normalized);
            $stmt->execute($params);
        } catch (\Throwable $e) {
            $this->logger->info('signals.attach_backtest.failed', [
                'symbols' => $normalized,
                'backtest_id' => $backtestId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
