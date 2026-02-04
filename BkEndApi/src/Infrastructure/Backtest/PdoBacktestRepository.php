<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Backtest;

use FinHub\Application\Backtest\BacktestRepositoryInterface;
use FinHub\Application\Backtest\BacktestRequest;
use FinHub\Infrastructure\Logging\LoggerInterface;
use PDO;

/**
 * Repositorio PDO para backtests y precios históricos.
 */
final class PdoBacktestRepository implements BacktestRepositoryInterface
{
    private PDO $pdo;
    private LoggerInterface $logger;

    public function __construct(PDO $pdo, LoggerInterface $logger)
    {
        $this->pdo = $pdo;
        $this->logger = $logger;
    }

    public function createBacktest(BacktestRequest $request, string $hash): int
    {
        $sql = <<<'SQL'
INSERT INTO backtests (
    user_id, strategy_id, universe, start_date, end_date, initial_capital,
    risk_per_trade_pct, commission_pct, min_fee, slippage_bps, spread_bps,
    breakout_lookback_buy, breakout_lookback_sell, atr_multiplier,
    request_json, request_hash, status, created_at, updated_at
) VALUES (
    :user_id, :strategy_id, :universe, :start_date, :end_date, :initial_capital,
    :risk_per_trade_pct, :commission_pct, :min_fee, :slippage_bps, :spread_bps,
    :breakout_lookback_buy, :breakout_lookback_sell, :atr_multiplier,
    :request_json, :request_hash, 'running', NOW(), NOW()
)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':user_id' => $request->userId,
            ':strategy_id' => $request->strategyId,
            ':universe' => json_encode($request->universe),
            ':start_date' => $request->startDate->format('Y-m-d'),
            ':end_date' => $request->endDate->format('Y-m-d'),
            ':initial_capital' => $request->initialCapital,
            ':risk_per_trade_pct' => $request->riskPerTradePct,
            ':commission_pct' => $request->commissionPct,
            ':min_fee' => $request->minFee,
            ':slippage_bps' => $request->slippageBps,
            ':spread_bps' => $request->spreadBps,
            ':breakout_lookback_buy' => $request->breakoutLookbackBuy,
            ':breakout_lookback_sell' => $request->breakoutLookbackSell,
            ':atr_multiplier' => $request->atrMultiplier,
            ':request_json' => json_encode($request->toArray()),
            ':request_hash' => $hash,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    public function persistResults(int $backtestId, array $summary, array $trades, array $equity, array $metrics): void
    {
        $this->pdo->beginTransaction();
        try {
            $update = 'UPDATE backtests SET status = :status, final_capital = :final_capital, updated_at = NOW() WHERE id = :id';
            $stmt = $this->pdo->prepare($update);
            $stmt->execute([
                ':status' => $summary['status'] ?? 'completed',
                ':final_capital' => $summary['final_capital'] ?? null,
                ':id' => $backtestId,
            ]);

            if (!empty($trades)) {
                $sql = <<<'SQL'
INSERT INTO backtest_trades (
    backtest_id, symbol, entry_ts, entry_price, exit_ts, exit_price, qty,
    pnl_gross, costs, pnl_net, exit_reason
) VALUES (
    :backtest_id, :symbol, :entry_ts, :entry_price, :exit_ts, :exit_price, :qty,
    :pnl_gross, :costs, :pnl_net, :exit_reason
)
SQL;
                $stmtTrade = $this->pdo->prepare($sql);
                foreach ($trades as $trade) {
                    $stmtTrade->execute([
                        ':backtest_id' => $backtestId,
                        ':symbol' => $trade['symbol'],
                        ':entry_ts' => $trade['entry_ts'],
                        ':entry_price' => $trade['entry_price'],
                        ':exit_ts' => $trade['exit_ts'],
                        ':exit_price' => $trade['exit_price'],
                        ':qty' => $trade['qty'],
                        ':pnl_gross' => $trade['pnl_gross'],
                        ':costs' => $trade['costs'],
                        ':pnl_net' => $trade['pnl_net'],
                        ':exit_reason' => $trade['exit_reason'],
                    ]);
                }
            }

            if (!empty($equity)) {
                $sqlEq = 'INSERT INTO backtest_equity (backtest_id, ts, equity, drawdown) VALUES (:backtest_id, :ts, :equity, :drawdown)';
                $stmtEq = $this->pdo->prepare($sqlEq);
                $peak = null;
                foreach ($equity as $row) {
                    $eq = (float) $row['equity'];
                    $peak = $peak === null ? $eq : max($peak, $eq);
                    $dd = $peak > 0 ? ($eq - $peak) / $peak : 0.0;
                    $stmtEq->execute([
                        ':backtest_id' => $backtestId,
                        ':ts' => $row['ts'],
                        ':equity' => $eq,
                        ':drawdown' => $dd,
                    ]);
                }
            }

            if (!empty($metrics)) {
                $sqlMt = <<<'SQL'
INSERT INTO backtest_metrics (
    backtest_id, cagr, max_drawdown, sharpe, sortino, win_rate, profit_factor, expectancy, exposure
) VALUES (
    :backtest_id, :cagr, :max_drawdown, :sharpe, :sortino, :win_rate, :profit_factor, :expectancy, :exposure
)
SQL;
                $stmtMt = $this->pdo->prepare($sqlMt);
                $stmtMt->execute([
                    ':backtest_id' => $backtestId,
                    ':cagr' => $metrics['CAGR'] ?? 0,
                    ':max_drawdown' => $metrics['max_drawdown'] ?? 0,
                    ':sharpe' => $metrics['sharpe'] ?? 0,
                    ':sortino' => $metrics['sortino'] ?? 0,
                    ':win_rate' => $metrics['win_rate'] ?? 0,
                    ':profit_factor' => $metrics['profit_factor'] ?? 0,
                    ':expectancy' => $metrics['expectancy'] ?? 0,
                    ':exposure' => $metrics['exposure'] ?? 0,
                ]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            $this->logger->error('backtest.persist.failed', [
                'backtest_id' => $backtestId,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function markFailed(int $backtestId, string $message): void
    {
        $sql = 'UPDATE backtests SET status = :status, error_message = :error_message, updated_at = NOW() WHERE id = :id';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':status' => 'failed',
            ':error_message' => substr($message, 0, 500),
            ':id' => $backtestId,
        ]);
    }

    public function getBacktest(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM backtests WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        if (isset($row['universe']) && is_string($row['universe'])) {
            $row['universe'] = json_decode($row['universe'], true);
        }
        if (isset($row['request_json']) && is_string($row['request_json'])) {
            $row['request_json'] = json_decode($row['request_json'], true);
        }
        return $row;
    }

    public function getTrades(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT symbol, entry_ts, entry_price, exit_ts, exit_price, qty, pnl_gross, costs, pnl_net, exit_reason FROM backtest_trades WHERE backtest_id = :id ORDER BY entry_ts ASC');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getEquity(int $id): array
    {
        $stmt = $this->pdo->prepare('SELECT ts, equity, drawdown FROM backtest_equity WHERE backtest_id = :id ORDER BY ts ASC');
        $stmt->execute([':id' => $id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function getMetrics(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT cagr, max_drawdown, sharpe, sortino, win_rate, profit_factor, expectancy, exposure FROM backtest_metrics WHERE backtest_id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function getPriceSeries(string $instrumentId, \DateTimeImmutable $start, \DateTimeImmutable $end): array
    {
        // El backtest recibe símbolos; resolvemos instrument_id vía join en portfolio_instruments (catálogo vigente).
        $sql = <<<'SQL'
SELECT p.as_of AS ts, p.open, p.high, p.low, p.close, p.volume
FROM prices p
JOIN portfolio_instruments i ON i.id = p.instrument_id
WHERE i.especie = :symbol AND p.as_of BETWEEN :start AND :end
ORDER BY p.as_of ASC
SQL;
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':symbol' => $instrumentId,
            ':start' => $start->format('Y-m-d'),
            ':end' => $end->format('Y-m-d'),
        ]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $indexed = [];
        foreach ($rows as $row) {
            // Normalizar a fecha (Y-m-d) para que coincida con el índice usado en el motor de backtest
            $ts = (new \DateTimeImmutable($row['ts']))->format('Y-m-d');
            $indexed[$ts] = [
                'open' => (float) $row['open'],
                'high' => (float) $row['high'],
                'low' => (float) $row['low'],
                'close' => (float) $row['close'],
                'volume' => (int) $row['volume'],
            ];
        }
        return $indexed;
    }
}
