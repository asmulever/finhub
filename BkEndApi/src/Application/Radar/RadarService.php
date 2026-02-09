<?php
declare(strict_types=1);

namespace FinHub\Application\Radar;

use FinHub\Application\DataLake\DataLakeService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Servicio de Radar: genera señales de compra/mantener/vender y régimen para CEDEARs/Bonos.
 * Versionado ligero (model_version) y trazabilidad via snapshot persistido.
 */
final class RadarService
{
    private RadarRepositoryInterface $repository;
    private DataLakeService $dataLake;
    private LoggerInterface $logger;
    private string $modelVersion = 'radar-heuristic-1';

    public function __construct(
        RadarRepositoryInterface $repository,
        DataLakeService $dataLake,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->dataLake = $dataLake;
        $this->logger = $logger;
    }

    /**
     * Analiza símbolos y devuelve snapshot + señales persistidas.
     *
     * @param array<int,string> $symbols
     * @param string $horizon '7d'|'30d'|'90d'
     * @param string $riskProfile 'conservador'|'moderado'|'agresivo'
     * @param array<string,mixed> $constraints
     * @return array<string,mixed>
     */
    public function analyze(array $symbols, string $horizon, string $riskProfile, array $constraints = []): array
    {
        $this->repository->ensureTables();
        $symbols = $this->normalizeSymbols($symbols);
        $asOf = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        $signals = [];
        foreach ($symbols as $symbol) {
            $signals[] = $this->buildSignal($symbol, $horizon, $riskProfile, $constraints, $asOf);
        }

        $snapshotId = $this->repository->storeSnapshot([
            'as_of' => $asOf,
            'model_version' => $this->modelVersion,
            'horizon' => $horizon,
            'risk_profile' => $riskProfile,
            'constraints' => $constraints,
            'signals' => $signals,
        ]);

        return [
            'snapshot_id' => $snapshotId,
            'as_of' => $asOf,
            'model_version' => $this->modelVersion,
            'signals' => $signals,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSignal(string $symbol, string $horizon, string $riskProfile, array $constraints, string $asOf): array
    {
        $series = [];
        try {
            $series = $this->dataLake->series($symbol, '6m')['points'] ?? [];
        } catch (\Throwable $e) {
            $this->logger->info('radar.series.failed', ['symbol' => $symbol, 'error' => $e->getMessage()]);
        }

        $close = $series ? (float) ($series[count($series) - 1]['close'] ?? $series[count($series) - 1]['price'] ?? 0) : 0;
        $mean = $this->mean($series);
        $vol = $this->stdev($series, $mean);
        $regime = $this->regime($series, $riskProfile);
        $action = $this->action($regime, $riskProfile);
        $confidence = $this->confidence($regime, $vol);

        $drivers = [
            'regime' => $regime,
            'volatility' => $vol,
            'trend_ma_ratio' => $mean > 0 ? ($close - $mean) / $mean : null,
            'risk_profile' => $riskProfile,
        ];

        return [
            'symbol' => $symbol,
            'action' => $action,
            'regime' => $regime,
            'confidence' => $confidence,
            'drivers' => $drivers,
            'price_last' => $close,
            'horizon' => $horizon,
            'risk_profile' => $riskProfile,
            'constraints' => $constraints,
            'model_version' => $this->modelVersion,
            'as_of' => $asOf,
            'data_freshness' => $series ? ($series[count($series) - 1]['t'] ?? $asOf) : null,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $series
     */
    private function mean(array $series): float
    {
        $vals = [];
        foreach ($series as $p) {
            if (isset($p['close']) && is_numeric($p['close'])) {
                $vals[] = (float) $p['close'];
            } elseif (isset($p['price']) && is_numeric($p['price'])) {
                $vals[] = (float) $p['price'];
            }
        }
        if (empty($vals)) {
            return 0.0;
        }
        return array_sum($vals) / count($vals);
    }

    /**
     * @param array<int,array<string,mixed>> $series
     */
    private function stdev(array $series, float $mean): float
    {
        $vals = [];
        foreach ($series as $p) {
            $c = $p['close'] ?? $p['price'] ?? null;
            if ($c !== null && is_numeric($c)) {
                $vals[] = (float) $c;
            }
        }
        $n = count($vals);
        if ($n < 2) {
            return 0.0;
        }
        $var = 0.0;
        foreach ($vals as $v) {
            $var += ($v - $mean) * ($v - $mean);
        }
        return sqrt($var / ($n - 1));
    }

    private function regime(array $series, string $riskProfile): string
    {
        $mean = $this->mean($series);
        $last = $series ? (float) ($series[count($series) - 1]['close'] ?? $series[count($series) - 1]['price'] ?? 0) : 0;
        $above = $mean > 0 ? ($last - $mean) / $mean : 0;
        if ($above > 0.05) {
            return 'risk-on';
        }
        if ($above < -0.05) {
            return 'risk-off';
        }
        return 'neutral';
    }

    private function action(string $regime, string $riskProfile): string
    {
        return match ($regime) {
            'risk-on' => $riskProfile === 'conservador' ? 'hold' : 'buy',
            'risk-off' => 'sell',
            default => 'hold',
        };
    }

    private function confidence(string $regime, float $vol): float
    {
        $base = match ($regime) {
            'risk-on' => 0.7,
            'risk-off' => 0.7,
            default => 0.5,
        };
        $volAdj = $vol > 0 ? max(0, 1 - min(1, $vol / 10)) : 0.8;
        return round(min(1, $base * $volAdj), 2);
    }

    /**
     * @param array<int,string> $symbols
     * @return array<int,string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $out = [];
        $seen = [];
        foreach ($symbols as $s) {
            $v = strtoupper(trim((string) $s));
            if ($v === '' || isset($seen[$v])) {
                continue;
            }
            $seen[$v] = true;
            $out[] = $v;
        }
        return $out;
    }
}
