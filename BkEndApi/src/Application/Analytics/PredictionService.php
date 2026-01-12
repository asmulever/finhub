<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Domain\User\UserRepositoryInterface;

/**
 * Caso de uso: calcula predicciones de valorización 30/60/90 días por instrumento.
 * Usa series del Data Lake para extraer momentum, SMA y RSI; guarda solo señal y confianza.
 */
final class PredictionService
{
    private PredictionRepositoryInterface $predictionRepository;
    private PredictionRunRepositoryInterface $runRepository;
    private PortfolioService $portfolioService;
    private DataLakeService $dataLakeService;
    private UserRepositoryInterface $userRepository;

    public function __construct(
        PredictionRepositoryInterface $predictionRepository,
        PredictionRunRepositoryInterface $runRepository,
        PortfolioService $portfolioService,
        DataLakeService $dataLakeService,
        UserRepositoryInterface $userRepository
    ) {
        $this->predictionRepository = $predictionRepository;
        $this->runRepository = $runRepository;
        $this->portfolioService = $portfolioService;
        $this->dataLakeService = $dataLakeService;
        $this->userRepository = $userRepository;
    }

    /**
     * Ejecuta predicción para el usuario actual. Devuelve estado y métricas.
     *
     * @return array<string,mixed>
     */
    public function runForUser(int $userId): array
    {
        $running = $this->runRepository->findRunning('user', $userId);
        if ($running !== null) {
            return ['status' => 'running', 'run' => $running];
        }

        $symbols = $this->portfolioService->listSymbols($userId);
        if (empty($symbols)) {
            return [
                'status' => 'skipped',
                'message' => 'Sin símbolos configurados para el usuario',
            ];
        }

        $runId = $this->runRepository->startRun('user', $userId);
        try {
            $result = $this->processSymbols($runId, $userId, $symbols);
            $this->runRepository->finishRun($runId, $result['status'], $result['summary']);
            return $result + ['run_id' => $runId];
        } catch (\Throwable $e) {
            $this->runRepository->finishRun($runId, 'failed', ['error' => $e->getMessage()]);
            throw $e;
        }
    }

    /**
     * Ejecuta predicción para todos los usuarios activos desde un endpoint público.
     *
     * @return array<string,mixed>
     */
    public function runGlobal(): array
    {
        $running = $this->runRepository->findRunning('global', null);
        if ($running !== null) {
            return ['status' => 'running', 'run' => $running];
        }

        $runId = $this->runRepository->startRun('global', null);
        $users = $this->userRepository->listAll();
        $processed = 0;
        $errors = [];
        $ok = 0;

        foreach ($users as $user) {
            if (!$user->isActive()) {
                continue;
            }
            try {
                $userResult = $this->runForUser($user->getId());
                $status = $userResult['status'] ?? '';
                if ($status !== 'failed') {
                    $ok++;
                }
                $processed++;
            } catch (\Throwable $e) {
                $errors[] = [
                    'user_id' => $user->getId(),
                    'message' => $e->getMessage(),
                ];
                $processed++;
            }
        }

        $status = $ok === 0 ? 'failed' : ($ok < $processed ? 'partial' : 'success');
        $summary = [
            'users_total' => $processed,
            'users_ok' => $ok,
            'errors' => $errors,
        ];
        $this->runRepository->finishRun($runId, $status, $summary);

        return [
            'status' => $status,
            'run_id' => $runId,
            'summary' => $summary,
        ];
    }

    /**
     * Devuelve predicciones más recientes para el usuario, incluyendo precio al momento de consulta.
     *
     * @return array<string,mixed>
     */
    public function latestForUser(int $userId): array
    {
        $running = $this->runRepository->findRunning('user', $userId);
        if ($running !== null) {
            return ['status' => 'running', 'run' => $running];
        }
        $run = $this->runRepository->findLatestFinished('user', $userId);
        if ($run === null) {
            return ['status' => 'empty', 'predictions' => []];
        }
        $predictions = $this->predictionRepository->findLatestByUser($userId);
        $withPrices = [];
        foreach ($predictions as $item) {
            $latest = $this->fetchLatestPrice($item['symbol']);
            $withPrices[] = $item + [
                'price' => $latest['price'],
                'price_as_of' => $latest['as_of'],
            ];
        }
        return [
            'status' => 'ready',
            'run' => $run,
            'predictions' => $withPrices,
        ];
    }

    /**
     * Ejecuta el cálculo por símbolo y persiste resultados.
     *
     * @param array<int,string> $symbols
     * @return array<string,mixed>
     */
    private function processSymbols(int $runId, int $userId, array $symbols): array
    {
        $ok = 0;
        $failed = 0;
        $errors = [];
        $nowIso = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $rows = [];
        foreach ($symbols as $symbol) {
            try {
                $series = $this->dataLakeService->series($symbol, '6m');
                $items = $this->computePredictions($series['points'] ?? []);
                foreach ($items as $pred) {
                    $rows[] = [
                        'symbol' => strtoupper($symbol),
                        'horizon' => $pred['horizon'],
                        'prediction' => $pred['prediction'],
                        'confidence' => $pred['confidence'],
                        'change_pct' => $pred['change_pct'],
                        'created_at' => $nowIso,
                    ];
                }
                $ok++;
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = ['symbol' => $symbol, 'reason' => $e->getMessage()];
            }
        }

        if (!empty($rows)) {
            $this->predictionRepository->replacePredictions($runId, $userId, $rows);
        }

        $status = $ok === 0 ? 'failed' : ($failed > 0 ? 'partial' : 'success');
        $summary = [
            'symbols_total' => count($symbols),
            'symbols_ok' => $ok,
            'symbols_failed' => $failed,
            'errors' => $errors,
        ];

        return [
            'status' => $status,
            'summary' => $summary,
        ];
    }

    /**
     * Construye predicciones 30/60/90 días a partir de la serie.
     *
     * @param array<int,array<string,mixed>> $points
     * @return array<int,array{horizon:int,prediction:string,confidence:float|null,change_pct:float|null}>
     */
    private function computePredictions(array $points): array
    {
        $closes = [];
        $dates = [];
        foreach ($points as $p) {
            if (isset($p['close']) && is_numeric($p['close'])) {
                $closes[] = (float) $p['close'];
            } elseif (isset($p['price']) && is_numeric($p['price'])) {
                $closes[] = (float) $p['price'];
            }
            if (isset($p['t'])) {
                $dates[] = (string) $p['t'];
            } elseif (isset($p['as_of'])) {
                $dates[] = (string) $p['as_of'];
            } elseif (isset($p['date'])) {
                $dates[] = (string) $p['date'];
            }
        }
        if (count($closes) < 20) {
            throw new \RuntimeException('Serie insuficiente para calcular SMA/RSI');
        }
        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $rsi14 = $this->rsi($closes, 14);
        $momentum = $this->momentum($closes, 10);
        $volatility = $this->volatility($closes, 20);
        $boll = $this->bollinger($closes, 20, 2);
        $macd = $this->macd($closes, 12, 26, 9);
        $atr = $this->atrFromCloses($closes, 14);
        $reg = $this->linearRegressionPct($closes, $dates);
        $last = end($closes) ?: null;
        $prev = count($closes) >= 2 ? $closes[count($closes) - 2] : null;
        $slope = null;
        if ($last !== null && $prev !== null && $prev != 0.0) {
            $slope = ($last - $prev) / $prev;
        }

        $indicators = [
            'sma20' => $sma20,
            'sma50' => $sma50,
            'rsi' => $rsi14,
            'momentum' => $momentum,
            'volatility' => $volatility,
            'slope' => $slope,
            'boll' => $boll,
            'macd' => $macd,
            'atr' => $atr,
            'reg' => $reg,
        ];

        $horizons = [30, 60, 90];
        $result = [];
        foreach ($horizons as $horizon) {
            $prediction = $this->classify($indicators, $horizon);
            $result[] = [
                'horizon' => $horizon,
                'prediction' => $prediction['direction'],
                'confidence' => $prediction['confidence'],
                'change_pct' => $prediction['change_pct'],
            ];
        }
        return $result;
    }

    /**
     * @return array{direction:string,confidence:float}
     */
    private function classify(array $indicators, int $horizon): array
    {
        $sma20 = $indicators['sma20'];
        $sma50 = $indicators['sma50'];
        $rsi = $indicators['rsi'];
        $momentum = $indicators['momentum'];
        $slope = $indicators['slope'] ?? 0.0;
        $volatility = $indicators['volatility'];
        $boll = $indicators['boll'];
        $macd = $indicators['macd'];
        $atr = $indicators['atr'];
        $reg = $indicators['reg'];

        $smaGap = ($sma20 !== null && $sma50 !== null && $sma50 !== 0.0)
            ? ($sma20 - $sma50) / $sma50
            : 0.0;
        $rsiBias = $rsi !== null ? ($rsi - 50.0) / 50.0 : 0.0;
        $momentumBias = $momentum;
        $slopeBias = $slope ?? 0.0;
        $bollBias = ($boll['z'] ?? 0.0) * -0.1;
        $macdBias = $macd['hist'] ?? 0.0;

        $baseScore = (0.35 * $smaGap) + (0.25 * $rsiBias) + (0.2 * $momentumBias) + (0.1 * $slopeBias) + (0.1 * $macdBias) + $bollBias;
        // Penalizar volatilidad para horizontes cortos
        if ($horizon <= 30) {
            $baseScore -= min(0.15, $volatility * 0.5);
        } elseif ($horizon >= 90) {
            $baseScore += max(-0.05, (0.1 - $volatility));
        }

        $direction = 'neutral';
        if ($baseScore > 0.08) {
            $direction = 'up';
        } elseif ($baseScore < -0.08) {
            $direction = 'down';
        }

        $confidence = max(0.05, min(0.95, 0.5 + ($baseScore / 1.6)));
        if ($direction === 'neutral') {
            $confidence = max(0.05, min(0.6, 0.5 - (abs($baseScore) / 2)));
        }

        $regProjected = $reg['slope_pct'] !== null ? $reg['slope_pct'] * $horizon : 0.0;
        $signalProjected = $baseScore * max(0.3, (1.0 - $volatility));
        $atrNoise = $atr !== null ? $atr * 0.001 : 0.0;
        $changePct = ($regProjected + $signalProjected) - $atrNoise;
        if ($direction === 'down') {
            $changePct = min($changePct, -abs($changePct));
        } elseif ($direction === 'up') {
            $changePct = max($changePct, abs($changePct));
        } else {
            $changePct = $changePct * 0.3;
        }

        return [
            'direction' => $direction,
            'confidence' => round($confidence, 4),
            'change_pct' => round($changePct, 4),
        ];
    }

    private function sma(array $values, int $window): ?float
    {
        if (count($values) < $window) {
            return null;
        }
        $slice = array_slice($values, -$window);
        $sum = array_sum($slice);
        return count($slice) > 0 ? $sum / count($slice) : null;
    }

    private function rsi(array $values, int $period): ?float
    {
        if (count($values) <= $period) {
            return null;
        }
        $gains = 0.0;
        $losses = 0.0;
        for ($i = count($values) - $period; $i < count($values); $i++) {
            if (!isset($values[$i - 1])) {
                continue;
            }
            $change = $values[$i] - $values[$i - 1];
            if ($change > 0) {
                $gains += $change;
            } else {
                $losses += abs($change);
            }
        }
        if ($gains === 0.0 && $losses === 0.0) {
            return 50.0;
        }
        if ($losses === 0.0) {
            return 70.0;
        }
        $rs = $gains / max($losses, 1e-6);
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function momentum(array $values, int $lag): float
    {
        $count = count($values);
        if ($count <= $lag) {
            return 0.0;
        }
        $current = (float) $values[$count - 1];
        $previous = (float) $values[$count - $lag - 1];
        if ($previous == 0.0) {
            return 0.0;
        }
        return ($current - $previous) / $previous;
    }

    private function volatility(array $values, int $window): float
    {
        if (count($values) < $window + 1) {
            return 0.0;
        }
        $slice = array_slice($values, -$window - 1);
        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            if ($slice[$i - 1] == 0.0) {
                continue;
            }
            $returns[] = ($slice[$i] - $slice[$i - 1]) / $slice[$i - 1];
        }
        if (empty($returns)) {
            return 0.0;
        }
        $avg = array_sum($returns) / count($returns);
        $sumSq = 0.0;
        foreach ($returns as $r) {
            $sumSq += ($r - $avg) ** 2;
        }
        return sqrt($sumSq / count($returns));
    }

    /**
     * @return array{mid:?float,upper:?float,lower:?float,z:?float}
     */
    private function bollinger(array $values, int $window, int $multiplier): array
    {
        if (count($values) < $window) {
            return ['mid' => null, 'upper' => null, 'lower' => null, 'z' => null];
        }
        $slice = array_slice($values, -$window);
        $avg = array_sum($slice) / count($slice);
        $variance = 0.0;
        foreach ($slice as $v) {
            $variance += ($v - $avg) ** 2;
        }
        $std = sqrt($variance / count($slice));
        $last = end($values);
        $z = $std > 0 ? ($last - $avg) / $std : null;
        return [
            'mid' => $avg,
            'upper' => $avg + $multiplier * $std,
            'lower' => $avg - $multiplier * $std,
            'z' => $z,
        ];
    }

    /**
     * MACD simple: EMA12 - EMA26 y su histograma contra EMA9.
     *
     * @return array{macd:?float,signal:?float,hist:?float}
     */
    private function macd(array $values, int $fast, int $slow, int $signal): array
    {
        if (count($values) < $slow) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }
        $emaFast = $this->ema($values, $fast);
        $emaSlow = $this->ema($values, $slow);
        if ($emaFast === null || $emaSlow === null) {
            return ['macd' => null, 'signal' => null, 'hist' => null];
        }
        $macd = $emaFast - $emaSlow;
        $signalVal = $this->ema(array_slice($values, -($signal + $slow)), $signal);
        $hist = $signalVal !== null ? $macd - $signalVal : null;
        return ['macd' => $macd, 'signal' => $signalVal, 'hist' => $hist];
    }

    private function ema(array $values, int $period): ?float
    {
        if (count($values) < $period) {
            return null;
        }
        $k = 2 / ($period + 1);
        $ema = array_sum(array_slice($values, 0, $period)) / $period;
        for ($i = $period; $i < count($values); $i++) {
            $ema = ($values[$i] - $ema) * $k + $ema;
        }
        return $ema;
    }

    private function atrFromCloses(array $values, int $period): ?float
    {
        if (count($values) <= $period) {
            return null;
        }
        $trs = [];
        for ($i = 1; $i < count($values); $i++) {
            $trs[] = abs($values[$i] - $values[$i - 1]);
        }
        if (count($trs) < $period) {
            return null;
        }
        return array_sum(array_slice($trs, -$period)) / $period;
    }

    /**
     * Regresión lineal simple sobre log-precio para estimar slope diario.
     *
     * @param array<int,string> $dates
     * @return array{slope_pct:?float}
     */
    private function linearRegressionPct(array $closes, array $dates): array
    {
        if (count($closes) < 10) {
            return ['slope_pct' => null];
        }
        $xs = [];
        $ys = [];
        foreach ($closes as $idx => $price) {
            $xs[] = (float) $idx;
            $ys[] = log(max(1e-6, (float) $price));
        }
        $n = count($xs);
        $sumX = array_sum($xs);
        $sumY = array_sum($ys);
        $sumXY = 0.0;
        $sumX2 = 0.0;
        for ($i = 0; $i < $n; $i++) {
            $sumXY += $xs[$i] * $ys[$i];
            $sumX2 += $xs[$i] * $xs[$i];
        }
        $den = ($n * $sumX2 - $sumX * $sumX);
        if ($den == 0.0) {
            return ['slope_pct' => null];
        }
        $slope = ($n * $sumXY - $sumX * $sumY) / $den;
        return ['slope_pct' => $slope];
    }

    /**
     * Obtiene el último precio almacenado en el Data Lake.
     *
     * @return array{price:float|null,as_of:string|null}
     */
    private function fetchLatestPrice(string $symbol): array
    {
        try {
            $quote = $this->dataLakeService->latestQuote($symbol);
            return [
                'price' => $quote['close'] ?? null,
                'as_of' => isset($quote['asOf']) ? (string) $quote['asOf'] : null,
            ];
        } catch (\Throwable $e) {
            return ['price' => null, 'as_of' => null];
        }
    }
}
