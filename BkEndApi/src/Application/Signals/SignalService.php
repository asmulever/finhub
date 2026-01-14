<?php
declare(strict_types=1);

namespace FinHub\Application\Signals;

use FinHub\Application\DataLake\DataLakeService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Motor de señales por reglas simples usando series del Data Lake.
 */
final class SignalService
{
    private SignalRepositoryInterface $repository;
    private DataLakeService $dataLake;
    private LoggerInterface $logger;

    public function __construct(
        SignalRepositoryInterface $repository,
        DataLakeService $dataLake,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->dataLake = $dataLake;
        $this->logger = $logger;
    }

    /**
     * Devuelve señales; si faltan o están vacías, recalcula desde Data Lake.
     *
     * @param array<int,string>|null $symbols
     * @param int $horizonDays
     * @param bool $forceRecompute Forzar recálculo aunque existan señales vigentes.
     * @param bool $collectMissing Ejecutar collect previo para símbolos pendientes.
     * @return array<int,array<string,mixed>>
     */
    public function latest(?array $symbols = null, int $horizonDays = 90, bool $forceRecompute = false, bool $collectMissing = false): array
    {
        $this->repository->deleteOlderThan(new \DateTimeImmutable('-20 days'));
        $symbols = $this->normalizeSymbols($symbols);
        $existing = $this->repository->findLatest($symbols);
        $existingMap = [];
        foreach ($existing as $row) {
            $existingMap[strtoupper((string) ($row['symbol'] ?? ''))] = $row;
        }

        $pending = $forceRecompute ? $symbols : [];
        if (!$forceRecompute) {
            foreach ($symbols as $sym) {
                $key = strtoupper($sym);
                if (!isset($existingMap[$key])) {
                    $pending[] = $key;
                }
            }
        }

        $symbolsToCollect = $forceRecompute ? $symbols : $pending;
        if ($collectMissing && !empty($symbolsToCollect)) {
            try {
                $this->dataLake->collect($symbolsToCollect);
            } catch (\Throwable $e) {
                $this->logger->info('signals.collect.failed', [
                    'symbols' => $symbolsToCollect,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        if (!empty($pending)) {
            $computed = $this->computeSignals($pending, $horizonDays);
            if (!empty($computed)) {
                if ($forceRecompute) {
                    $symbolsToReplace = array_values(array_filter(array_map(static fn ($s) => $s['symbol'] ?? null, $computed), static fn ($s) => is_string($s) && $s !== ''));
                    $this->repository->deleteBySymbols($symbolsToReplace);
                }
                $this->repository->saveSignals($computed);
                foreach ($computed as $sig) {
                    $k = strtoupper((string) ($sig['symbol'] ?? ''));
                    $existingMap[$k] = $sig;
                }
            }
        }

        return array_values($existingMap);
    }

    /**
     * Calcula señales por reglas básicas usando serie 6m del Data Lake.
     *
     * @param array<int,string> $symbols
     * @return array<int,array<string,mixed>>
     */
    private function computeSignals(array $symbols, int $horizonDays): array
    {
        $results = [];
        foreach ($symbols as $sym) {
            try {
                $series = $this->dataLake->series($sym, '6m');
                $points = $series['points'] ?? [];
                $calc = $this->calculateFromSeries($sym, $points, $horizonDays);
                if ($calc !== null) {
                    $results[] = $calc;
                }
            } catch (\Throwable $e) {
                $this->logger->info('signals.compute.failed', [
                    'symbol' => $sym,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return $results;
    }

    /**
     * @param array<int,array<string,mixed>> $points
     */
    private function calculateFromSeries(string $symbol, array $points, int $horizonDays): ?array
    {
        if (empty($points)) {
            return null;
        }
        $closes = [];
        $dates = [];
        foreach ($points as $p) {
            $t = $p['t'] ?? null;
            $c = $p['close'] ?? $p['price'] ?? null;
            if ($t === null || $c === null || !is_numeric($c)) {
                continue;
            }
            $closes[] = (float) $c;
            $dates[] = new \DateTimeImmutable((string) $t);
        }
        $count = count($closes);
        if ($count < 20) {
            return null;
        }
        $lastClose = $closes[$count - 1];
        $ema20 = $this->ema($closes, 20);
        $ema50 = $this->ema($closes, 50);
        $rsi14 = $this->rsi($closes, 14);
        $atr14 = $this->atr($points, 14);
        $boll = $this->bollinger($closes, 20, 2);

        $trend = $ema20 !== null && $ema50 !== null
            ? ($ema20 > $ema50 ? 'UP' : ($ema20 < $ema50 ? 'DOWN' : 'SIDE'))
            : null;
        $momentum = $rsi14 !== null
            ? ($rsi14 > 60 ? 'BULL' : ($rsi14 < 40 ? 'BEAR' : 'NEUTRAL'))
            : null;

        $action = 'HOLD';
        if ($trend === 'UP' && $rsi14 !== null && $rsi14 > 55) {
            $action = 'BUY';
        } elseif ($trend === 'DOWN' && $rsi14 !== null && $rsi14 < 45) {
            $action = 'SELL';
        }

        $confidence = $this->computeConfidence($trend, $rsi14);
        $signalStrength = ($ema20 !== null && $ema50 !== null) ? ($ema20 - $ema50) : 0.0;
        $expReturnPct = $this->expectedReturn($closes, $horizonDays);
        $range = $this->percentileRange($closes, $horizonDays);

        $stopPrice = $atr14 !== null ? max(0, $lastClose - 2 * $atr14) : null;
        $takePrice = $atr14 !== null ? $lastClose + 2 * $atr14 : null;
        $stopDist = ($stopPrice !== null && $lastClose > 0) ? ($lastClose - $stopPrice) / $lastClose : null;
        $takeDist = ($takePrice !== null && $lastClose > 0) ? ($takePrice - $lastClose) / $lastClose : null;
        $riskReward = ($stopDist !== null && $stopDist > 0 && $takeDist !== null) ? $takeDist / $stopDist : null;

        $seriesJson = $this->buildIndicatorSeries($points, $boll);

        return [
            'symbol' => strtoupper($symbol),
            'especie' => strtoupper($symbol),
            'name' => null,
            'exchange' => null,
            'currency' => null,
            'as_of' => $dates[$count - 1]->format('Y-m-d H:i:s'),
            'action' => $action,
            'confidence' => $confidence,
            'horizon_days' => $horizonDays,
            'signal_strength' => $signalStrength,
            'price_last' => $lastClose,
            'entry_reference' => $lastClose,
            'exp_return_pct' => $expReturnPct,
            'exp_return_amt' => null,
            'range_p10_pct' => $range['p10'],
            'range_p50_pct' => $range['p50'],
            'range_p90_pct' => $range['p90'],
            'range_p10_amt' => null,
            'range_p90_amt' => null,
            'volatility_atr' => $atr14,
            'stop_price' => $stopPrice,
            'take_price' => $takePrice,
            'stop_distance_pct' => $stopDist,
            'take_distance_pct' => $takeDist,
            'risk_reward' => $riskReward,
            'trend_state' => $trend,
            'momentum_state' => $momentum,
            'regime' => $atr14 !== null ? ($atr14 / max(1e-6, $lastClose) > 0.05 ? 'HIGH_VOL' : 'LOW_VOL') : null,
            'rationale_short' => $this->buildRationale($action, $trend, $momentum, $rsi14),
            'rationale_tags' => $this->buildTags($trend, $momentum, $rsi14),
            'rationale_json' => [
                'ema20' => $ema20,
                'ema50' => $ema50,
                'rsi14' => $rsi14,
                'atr14' => $atr14,
                'bollinger' => [
                    'upper' => $boll['upper'],
                    'mid' => $boll['mid'],
                    'lower' => $boll['lower'],
                ],
            ],
            'data_quality' => 'OK',
            'data_points_used' => $count,
            'costs_included' => 0,
            'backtest_ref' => null,
            'series_json' => $seriesJson,
        ];
    }

    private function computeConfidence(?string $trend, ?float $rsi): float
    {
        $base = 0.4;
        if ($trend === 'UP' || $trend === 'DOWN') {
            $base = 0.6;
        }
        if ($rsi !== null) {
            if ($rsi > 70 || $rsi < 30) {
                $base += 0.1;
            }
        }
        return (float) min(1, max(0, $base));
    }

    private function expectedReturn(array $closes, int $horizonDays): float
    {
        $window = min(count($closes), max(5, (int) ($horizonDays / 2)));
        $slice = array_slice($closes, -$window);
        $first = $slice[0];
        $last = $slice[count($slice) - 1];
        if ($first <= 0) return 0.0;
        $ret = ($last - $first) / $first;
        return (float) $ret;
    }

    private function percentileRange(array $closes, int $horizonDays): array
    {
        $returns = [];
        for ($i = 1; $i < count($closes); $i++) {
            $prev = $closes[$i - 1];
            if ($prev <= 0) continue;
            $ret = ($closes[$i] - $prev) / $prev;
            $returns[] = $ret;
        }
        sort($returns);
        $count = count($returns);
        if ($count === 0) {
            return ['p10' => 0.0, 'p50' => 0.0, 'p90' => 0.0];
        }
        $p10 = $returns[(int) floor(0.1 * ($count - 1))];
        $p50 = $returns[(int) floor(0.5 * ($count - 1))];
        $p90 = $returns[(int) floor(0.9 * ($count - 1))];
        return ['p10' => $p10, 'p50' => $p50, 'p90' => $p90];
    }

    /**
     * @param array<int,array<string,mixed>> $points
     */
    private function atr(array $points, int $period = 14): ?float
    {
        $trs = [];
        for ($i = 1; $i < count($points); $i++) {
            $prevClose = (float) ($points[$i - 1]['close'] ?? $points[$i - 1]['price'] ?? 0);
            $high = (float) ($points[$i]['high'] ?? $points[$i]['price'] ?? $prevClose);
            $low = (float) ($points[$i]['low'] ?? $points[$i]['price'] ?? $prevClose);
            $tr = max($high - $low, abs($high - $prevClose), abs($low - $prevClose));
            $trs[] = $tr;
        }
        if (count($trs) < $period) {
            return null;
        }
        $slice = array_slice($trs, -$period);
        return array_sum($slice) / count($slice);
    }

    private function rsi(array $closes, int $period = 14): ?float
    {
        if (count($closes) <= $period) return null;
        $gains = 0;
        $losses = 0;
        for ($i = count($closes) - $period; $i < count($closes); $i++) {
            $change = $closes[$i] - $closes[$i - 1];
            if ($change > 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }
        if ($losses == 0) return 70.0;
        $rs = $gains / $losses;
        return 100 - 100 / (1 + $rs);
    }

    private function ema(array $closes, int $period): ?float
    {
        if (count($closes) < $period) return null;
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($closes, 0, $period)) / $period;
        for ($i = $period; $i < count($closes); $i++) {
            $ema = ($closes[$i] - $ema) * $k + $ema;
        }
        return $ema;
    }

    private function bollinger(array $closes, int $period = 20, float $multiplier = 2.0): array
    {
        if (count($closes) < $period) {
            return ['upper' => null, 'mid' => null, 'lower' => null];
        }
        $slice = array_slice($closes, -$period);
        $avg = array_sum($slice) / count($slice);
        $variance = 0.0;
        foreach ($slice as $c) {
            $variance += ($c - $avg) ** 2;
        }
        $variance /= count($slice);
        $std = sqrt($variance);
        return [
            'upper' => $avg + $multiplier * $std,
            'mid' => $avg,
            'lower' => $avg - $multiplier * $std,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $points
     */
    private function buildIndicatorSeries(array $points, array $boll): array
    {
        $series = [];
        $closes = [];
        foreach ($points as $p) {
            $c = $p['close'] ?? $p['price'] ?? null;
            $t = $p['t'] ?? $p['as_of'] ?? $p['fecha'] ?? null;
            if ($c === null || $t === null) {
                continue;
            }
            $closes[] = (float) $c;
            $ema20 = $this->ema($closes, 20);
            $ema50 = $this->ema($closes, 50);
            $rsi = $this->rsi($closes, 14);
            $series[] = [
                't' => $t,
                'close' => (float) $c,
                'ema20' => $ema20,
                'ema50' => $ema50,
                'rsi14' => $rsi,
                'bb_upper' => $boll['upper'],
                'bb_mid' => $boll['mid'],
                'bb_lower' => $boll['lower'],
            ];
        }
        return $series;
    }

    private function buildRationale(string $action, ?string $trend, ?string $momentum, ?float $rsi): string
    {
        $parts = [];
        if ($trend) $parts[] = "Tendencia {$trend}";
        if ($momentum) $parts[] = "Momentum {$momentum}";
        if ($rsi !== null) $parts[] = "RSI " . number_format($rsi, 1);
        return sprintf('%s | %s', $action, implode(' · ', $parts));
    }

    private function buildTags(?string $trend, ?string $momentum, ?float $rsi): array
    {
        $tags = [];
        if ($trend === 'UP') $tags[] = 'EMA_TREND_UP';
        if ($trend === 'DOWN') $tags[] = 'EMA_TREND_DOWN';
        if ($momentum === 'BULL') $tags[] = 'RSI_BULL';
        if ($momentum === 'BEAR') $tags[] = 'RSI_BEAR';
        if ($rsi !== null) {
            if ($rsi > 70) $tags[] = 'RSI_OVERBOUGHT';
            if ($rsi < 30) $tags[] = 'RSI_OVERSOLD';
        }
        return $tags;
    }

    /**
     * @param array<int,string>|null $symbols
     * @return array<int,string>
     */
    private function normalizeSymbols(?array $symbols): array
    {
        if ($symbols === null || empty($symbols)) {
            return [];
        }
        $out = [];
        foreach ($symbols as $s) {
            $s = strtoupper(trim((string) $s));
            if ($s === '') continue;
            $out[] = $s;
        }
        return array_values(array_unique($out));
    }
}
