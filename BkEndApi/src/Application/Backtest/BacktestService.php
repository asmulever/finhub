<?php
declare(strict_types=1);

namespace FinHub\Application\Backtest;

use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Motor de backtesting bar-by-bar para estrategias rule-based.
 */
final class BacktestService
{
    private BacktestRepositoryInterface $repository;
    private LoggerInterface $logger;

    public function __construct(BacktestRepositoryInterface $repository, LoggerInterface $logger)
    {
        $this->repository = $repository;
        $this->logger = $logger;
    }

    public function getRepository(): BacktestRepositoryInterface
    {
        return $this->repository;
    }

    public function getBacktest(int $id): ?array
    {
        return $this->repository->getBacktest($id);
    }

    public function getTrades(int $id): array
    {
        return $this->repository->getTrades($id);
    }

    public function getEquity(int $id): array
    {
        return $this->repository->getEquity($id);
    }

    public function getMetrics(int $id): ?array
    {
        return $this->repository->getMetrics($id);
    }

    public function run(BacktestRequest $request): int
    {
        $hash = hash('sha256', json_encode($request->toArray(), JSON_THROW_ON_ERROR));
        $backtestId = $this->repository->createBacktest($request, $hash);

        try {
            $result = $this->executeTrendBreakout($request);
            $summary = [
                'final_capital' => $result['final_equity'],
                'status' => 'completed',
                'hash' => $hash,
            ];
            $this->repository->persistResults($backtestId, $summary, $result['trades'], $result['equity'], $result['metrics']);
        } catch (\Throwable $e) {
            $this->logger->error('backtest.run.failed', [
                'backtest_id' => $backtestId,
                'message' => $e->getMessage(),
            ]);
            $this->repository->markFailed($backtestId, $e->getMessage());
            throw $e;
        }

        return $backtestId;
    }

    /**
     * Ejecuta estrategia trend_breakout: BUY si close rompe máximo N días,
     * SELL si close cae por debajo del mínimo M días. Long-only, fill al close.
     *
     * @return array{trades:array<int,array<string,mixed>>,equity:array<int,array<string,mixed>>,metrics:array<string,mixed>,final_equity:float}
     */
    private function executeTrendBreakout(BacktestRequest $request): array
    {
        if ($request->strategyId !== 'trend_breakout') {
            throw new \InvalidArgumentException('Estrategia no soportada: ' . $request->strategyId);
        }
        $seriesBySymbol = [];
        foreach ($request->universe as $symbol) {
            $series = $this->repository->getPriceSeries($symbol, $request->startDate, $request->endDate);
            if (!empty($series)) {
                $seriesBySymbol[$symbol] = $series;
            }
        }
        if (empty($seriesBySymbol)) {
            throw new \RuntimeException('No hay datos de precios para el universo y rango solicitados', 422);
        }

        $dates = $this->buildDateIndex($seriesBySymbol);
        $positions = [];
        $cash = $request->initialCapital;
        $equitySeries = [];
        $trades = [];
        $daysInMarket = 0;

        foreach ($dates as $date) {
            $dailyValuation = 0.0;
            foreach ($seriesBySymbol as $symbol => $rows) {
                $bar = $rows[$date->format('Y-m-d')] ?? null;
                if ($bar === null) {
                    continue;
                }

                // Actualizar stops y salidas
                if (isset($positions[$symbol])) {
                    $pos = $positions[$symbol];
                    $exitReason = null;
                    $stopPrice = $pos['stop'];
                    $close = $bar['close'];
                    if ($stopPrice !== null && $close <= $stopPrice) {
                        $exitReason = 'stop';
                    } else {
                        $lookbackLow = $this->lowest($rows, $date, $request->breakoutLookbackSell);
                        if ($lookbackLow !== null && $close < $lookbackLow) {
                            $exitReason = 'signal';
                        }
                    }
                    if ($exitReason !== null) {
                        $exitPriceRaw = $close;
                        $exitPriceExec = $this->applySellCost($exitPriceRaw, $request->slippageBps, $request->spreadBps);
                        $exitCosts = $this->calculateCommission($exitPriceExec * $pos['qty'], $request->commissionPct, $request->minFee);
                        $gross = ($exitPriceExec - $pos['entry_price_exec']) * $pos['qty'];
                        $pnlNet = $gross - $pos['entry_costs'] - $exitCosts;

                        $cash += ($exitPriceExec * $pos['qty']) - $exitCosts;
                        $trades[] = [
                            'symbol' => $symbol,
                            'entry_ts' => $pos['entry_ts'],
                            'entry_price' => $pos['entry_price_exec'],
                            'exit_ts' => $date->format('Y-m-d'),
                            'exit_price' => $exitPriceExec,
                            'qty' => $pos['qty'],
                            'pnl_gross' => $gross,
                            'costs' => $pos['entry_costs'] + $exitCosts,
                            'pnl_net' => $pnlNet,
                            'exit_reason' => $exitReason,
                        ];
                        unset($positions[$symbol]);
                    }
                }

                // Entradas
                if (!isset($positions[$symbol])) {
                    $lookbackHigh = $this->highest($rows, $date, $request->breakoutLookbackBuy);
                    $close = $bar['close'];
                    if ($lookbackHigh !== null && $close > $lookbackHigh) {
                        $atr = $this->atr($rows, $date, 14);
                        $stop = $atr !== null ? $close - ($atr * $request->atrMultiplier) : $this->lowest($rows, $date, $request->breakoutLookbackSell);
                        if ($stop !== null && $close > $stop) {
                            $stopDistance = $close - $stop;
                            $riskAmount = $cash * ($request->riskPerTradePct / 100);
                            if ($stopDistance > 0 && $riskAmount > 0) {
                                $qty = (int) floor($riskAmount / $stopDistance);
                                if ($qty > 0) {
                                    $entryPriceExec = $this->applyBuyCost($close, $request->slippageBps, $request->spreadBps);
                                    $entryCost = $this->calculateCommission($entryPriceExec * $qty, $request->commissionPct, $request->minFee);
                                    $total = ($entryPriceExec * $qty) + $entryCost;
                                    if ($cash >= $total) {
                                        $cash -= $total;
                                        $positions[$symbol] = [
                                            'qty' => $qty,
                                            'entry_price_exec' => $entryPriceExec,
                                            'entry_costs' => $entryCost,
                                            'stop' => $stop,
                                            'entry_ts' => $date->format('Y-m-d'),
                                        ];
                                    }
                                }
                            }
                        }
                    }
                }

                // Valorización diaria
                if (isset($positions[$symbol])) {
                    $dailyValuation += $bar['close'] * $positions[$symbol]['qty'];
                }
            }

            $equity = $cash + $dailyValuation;
            $equitySeries[] = [
                'ts' => $date->format('Y-m-d'),
                'equity' => $equity,
            ];
            if ($dailyValuation > 0) {
                $daysInMarket++;
            }
        }

        $metrics = $this->buildMetrics($equitySeries, $trades, $request->initialCapital, $daysInMarket);

        return [
            'trades' => $trades,
            'equity' => $equitySeries,
            'metrics' => $metrics,
            'final_equity' => (float) ($equitySeries[count($equitySeries) - 1]['equity'] ?? $request->initialCapital),
        ];
    }

    /**
     * @param array<string,array<string,array<string,float|int>>> $seriesBySymbol
     * @return array<int,\DateTimeImmutable>
     */
    private function buildDateIndex(array $seriesBySymbol): array
    {
        $dates = [];
        foreach ($seriesBySymbol as $rows) {
            foreach ($rows as $ts => $_row) {
                $dates[$ts] = new \DateTimeImmutable($ts);
            }
        }
        ksort($dates);
        return array_values($dates);
    }

    /**
     * @param array<string,array<string,float|int>> $rows
     */
    private function highest(array $rows, \DateTimeImmutable $current, int $lookback): ?float
    {
        $target = $current->modify(sprintf('-%d days', $lookback));
        $max = null;
        foreach ($rows as $ts => $row) {
            $date = new \DateTimeImmutable($ts);
            if ($date >= $target && $date < $current) {
                $h = $row['high'] ?? $row['close'] ?? null;
                if (is_numeric($h)) {
                    $max = $max === null ? (float) $h : max($max, (float) $h);
                }
            }
        }
        return $max;
    }

    /**
     * @param array<string,array<string,float|int>> $rows
     */
    private function lowest(array $rows, \DateTimeImmutable $current, int $lookback): ?float
    {
        $target = $current->modify(sprintf('-%d days', $lookback));
        $min = null;
        foreach ($rows as $ts => $row) {
            $date = new \DateTimeImmutable($ts);
            if ($date >= $target && $date < $current) {
                $l = $row['low'] ?? $row['close'] ?? null;
                if (is_numeric($l)) {
                    $min = $min === null ? (float) $l : min($min, (float) $l);
                }
            }
        }
        return $min;
    }

    /**
     * @param array<string,array<string,float|int>> $rows
     */
    private function atr(array $rows, \DateTimeImmutable $current, int $period): ?float
    {
        $trValues = [];
        $dates = array_keys($rows);
        sort($dates);
        $prevClose = null;
        foreach ($dates as $ts) {
            $row = $rows[$ts];
            $high = $row['high'] ?? null;
            $low = $row['low'] ?? null;
            $close = $row['close'] ?? null;
            if (!is_numeric($high) || !is_numeric($low) || !is_numeric($close)) {
                $prevClose = $close;
                continue;
            }
            $tr = (float) ($high - $low);
            if ($prevClose !== null) {
                $tr = max($tr, abs((float) $high - (float) $prevClose), abs((float) $low - (float) $prevClose));
            }
            $trValues[$ts] = $tr;
            $prevClose = (float) $close;
        }
        $cutoff = $current->modify(sprintf('-%d days', $period));
        $filtered = [];
        foreach ($trValues as $ts => $tr) {
            $date = new \DateTimeImmutable($ts);
            if ($date <= $current && $date > $cutoff) {
                $filtered[] = $tr;
            }
        }
        if (count($filtered) < $period / 2) {
            return null;
        }
        return array_sum($filtered) / count($filtered);
    }

    private function applyBuyCost(float $price, float $slippageBps, float $spreadBps): float
    {
        $factor = 1 + (($slippageBps + $spreadBps) / 10000);
        return $price * $factor;
    }

    private function applySellCost(float $price, float $slippageBps, float $spreadBps): float
    {
        $factor = 1 - (($slippageBps + $spreadBps) / 10000);
        return $price * $factor;
    }

    private function calculateCommission(float $gross, float $commissionPct, float $minFee): float
    {
        $fee = $gross * ($commissionPct / 100);
        return max($fee, $minFee);
    }

    /**
     * @param array<int,array{ts:string,equity:float}> $equity
     * @param array<int,array<string,mixed>> $trades
     * @return array<string,mixed>
     */
    private function buildMetrics(array $equity, array $trades, float $initialCapital, int $daysInMarket): array
    {
        if (count($equity) < 2) {
            return [
                'CAGR' => 0.0,
                'max_drawdown' => 0.0,
                'sharpe' => 0.0,
                'sortino' => 0.0,
                'win_rate' => 0.0,
                'profit_factor' => 0.0,
                'expectancy' => 0.0,
                'exposure' => 0.0,
            ];
        }
        $firstDate = new \DateTimeImmutable($equity[0]['ts']);
        $lastDate = new \DateTimeImmutable($equity[count($equity) - 1]['ts']);
        $days = max(1, (int) $lastDate->diff($firstDate)->format('%a'));
        $final = (float) ($equity[count($equity) - 1]['equity'] ?? $initialCapital);
        $cagr = pow($final / max(1e-6, $initialCapital), 365 / $days) - 1;

        $maxEquity = $equity[0]['equity'];
        $maxDrawdown = 0.0;
        $returns = [];
        $downside = [];
        for ($i = 1; $i < count($equity); $i++) {
            $prev = $equity[$i - 1]['equity'];
            $curr = $equity[$i]['equity'];
            $ret = ($curr - $prev) / max(1e-6, $prev);
            $returns[] = $ret;
            if ($ret < 0) {
                $downside[] = $ret;
            }
            $maxEquity = max($maxEquity, $curr);
            $dd = ($curr - $maxEquity) / max(1e-6, $maxEquity);
            $maxDrawdown = min($maxDrawdown, $dd);
        }
        $avg = array_sum($returns) / count($returns);
        $std = $this->stddev($returns);
        $downStd = $this->stddev($downside);
        $sharpe = $std > 0 ? ($avg / $std) * sqrt(252) : 0.0;
        $sortino = $downStd > 0 ? ($avg / $downStd) * sqrt(252) : 0.0;

        $wins = array_filter($trades, static fn ($t) => ($t['pnl_net'] ?? 0) > 0);
        $tradesCount = count($trades);
        $winRate = $tradesCount > 0 ? count($wins) / $tradesCount : 0.0;
        $grossWin = (float) array_sum(array_map(static fn ($t) => max(0, (float) ($t['pnl_net'] ?? 0)), $trades));
        $grossLoss = (float) array_sum(array_map(static fn ($t) => min(0, (float) ($t['pnl_net'] ?? 0)), $trades));
        $denominator = abs($grossLoss);
        $profitFactor = $denominator > 0.0 ? ($grossWin / $denominator) : 0.0;
        $expectancy = $tradesCount > 0 ? array_sum(array_map(static fn ($t) => (float) ($t['pnl_net'] ?? 0), $trades)) / $tradesCount : 0.0;
        $exposure = count($equity) > 0 ? $daysInMarket / count($equity) : 0.0;

        return [
            'CAGR' => $cagr,
            'max_drawdown' => $maxDrawdown,
            'sharpe' => $sharpe,
            'sortino' => $sortino,
            'win_rate' => $winRate,
            'profit_factor' => $profitFactor,
            'expectancy' => $expectancy,
            'exposure' => $exposure,
        ];
    }

    /**
     * @param array<int,float> $values
     */
    private function stddev(array $values): float
    {
        $n = count($values);
        if ($n === 0) {
            return 0.0;
        }
        $avg = array_sum($values) / $n;
        $var = 0.0;
        foreach ($values as $v) {
            $var += ($v - $avg) ** 2;
        }
        return sqrt($var / $n);
    }
}
