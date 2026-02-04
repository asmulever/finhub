<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\PriceService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Arma un resumen del portafolio con precios y señales básicas persistiendo análisis en el Data Lake.
 */
final class PortfolioSummaryService
{
    private PortfolioService $portfolioService;
    private DataLakeService $dataLakeService;
    private PriceService $priceService;
    private LoggerInterface $logger;

    public function __construct(
        PortfolioService $portfolioService,
        DataLakeService $dataLakeService,
        PriceService $priceService,
        LoggerInterface $logger
    ) {
        $this->portfolioService = $portfolioService;
        $this->dataLakeService = $dataLakeService;
        $this->priceService = $priceService;
        $this->logger = $logger;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function summaryForUser(int $userId): array
    {
        $instruments = $this->portfolioService->listInstruments($userId);
        $now = new \DateTimeImmutable();
        $result = [];

        foreach ($instruments as $instrument) {
            $especie = strtoupper((string) ($instrument['especie'] ?? $instrument['symbol'] ?? ''));
            if ($especie === '') {
                continue;
            }
            $symbolBase = $this->symbolBase($especie);
            $symbolForFetch = $especie;
            try {
                $quote = $this->fetchQuoteWithFallback($symbolForFetch, $symbolBase);
                $series = $this->dataLakeService->series($symbolForFetch, '3m')['points'] ?? [];
                $indicators = $this->buildIndicators($series);
                $analysisSnapshot = [
                    'symbol' => $symbolForFetch,
                    'signal' => $indicators['signal'],
                    'sma20' => $indicators['sma20'],
                    'sma50' => $indicators['sma50'],
                    'volatility_30d' => $indicators['volatility_30d'],
                    'price' => $quote['close'],
                    'as_of' => $quote['asOf'] ?? $now->format(\DateTimeInterface::ATOM),
                ];
                $this->dataLakeService->storeAnalysisSnapshot($symbolForFetch, $analysisSnapshot);
                $result[] = [
                    'especie' => $especie,
                    'symbol' => $symbolBase,
                    'name' => $instrument['name'] ?? $instrument['nombre'] ?? $especie,
                    'type' => $instrument['type'] ?? $instrument['tipo'] ?? '',
                    'exchange' => $instrument['exchange'] ?? $instrument['mercado'] ?? '',
                    'currency' => $quote['currency'] ?? $instrument['currency'] ?? '',
                    'provider' => $quote['source'] ?? $quote['provider'] ?? '',
                    'price' => $quote['close'],
                    'open' => $quote['open'] ?? null,
                    'high' => $quote['high'] ?? null,
                    'low' => $quote['low'] ?? null,
                    'previous_close' => $quote['previousClose'] ?? null,
                    'as_of' => $quote['asOf'] ?? null,
                    'var_pct' => $this->variationPct($quote['close'] ?? null, $quote['previousClose'] ?? null),
                    'var_mtd' => $quote['var_mtd'] ?? null,
                    'var_ytd' => $quote['var_ytd'] ?? null,
                    'volume_nominal' => $quote['volume_nominal'] ?? null,
                    'volume_efectivo' => $quote['volume_efectivo'] ?? null,
                    'signal' => $indicators['signal'],
                    'sma20' => $indicators['sma20'],
                    'sma50' => $indicators['sma50'],
                    'volatility_30d' => $indicators['volatility_30d'],
                ];
            } catch (\Throwable $e) {
                $this->logger->info('portfolio.summary.symbol_failed', [
                    'symbol' => $especie,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return $result;
    }

    private function fetchQuoteWithFallback(string $especie, string $symbolBase): array
    {
        try {
            return $this->dataLakeService->latestQuote($especie);
        } catch (\Throwable $e) {
            $this->logger->info('portfolio.summary.datalake_miss', [
                'symbol' => $especie,
                'message' => $e->getMessage(),
            ]);
        }
        $symbolForProvider = $symbolBase !== '' ? $symbolBase : $especie;
        $request = new PriceRequest($symbolForProvider);
        $quote = $this->priceService->getPrice($request);
        $close = $quote['close'] ?? $quote['price'] ?? $quote['c'] ?? null;
        return [
            'symbol' => $symbolForProvider,
            'close' => $close,
            'open' => $quote['open'] ?? $quote['o'] ?? null,
            'high' => $quote['high'] ?? $quote['h'] ?? null,
            'low' => $quote['low'] ?? $quote['l'] ?? null,
            'previousClose' => $quote['previousClose'] ?? $quote['pc'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'asOf' => $quote['asOf'] ?? null,
            'source' => $quote['source'] ?? $quote['provider'] ?? null,
            'volume_nominal' => $quote['volumen_nominal'] ?? $quote['volume_nominal'] ?? null,
            'volume_efectivo' => $quote['volumen_efectivo'] ?? $quote['volume_efectivo'] ?? null,
            'var_mtd' => $quote['var_mtd'] ?? null,
            'var_ytd' => $quote['var_ytd'] ?? null,
        ];
    }

    private function symbolBase(string $especie): string
    {
        $trimmed = strtoupper(trim($especie));
        if ($trimmed === '') {
            return '';
        }
        $parts = explode('-', $trimmed);
        return $parts[0] ?? $trimmed;
    }

    /**
     * @param array<int,array<string,mixed>> $series
     * @return array<string,mixed>
     */
    private function buildIndicators(array $series): array
    {
        $closes = [];
        foreach ($series as $point) {
            $price = $point['close'] ?? $point['price'] ?? null;
            if (is_numeric($price)) {
                $closes[] = (float) $price;
            }
        }
        $sma20 = $this->sma($closes, 20);
        $sma50 = $this->sma($closes, 50);
        $signal = 'Neutral';
        if ($sma20 !== null && $sma50 !== null) {
            if ($sma20 > $sma50 * 1.001) {
                $signal = 'Compra';
            } elseif ($sma20 < $sma50 * 0.999) {
                $signal = 'Venta';
            }
        }

        $volatility = $this->volatility($closes, 30);

        return [
            'sma20' => $sma20,
            'sma50' => $sma50,
            'signal' => $signal,
            'volatility_30d' => $volatility,
        ];
    }

    private function sma(array $values, int $window): ?float
    {
        if (count($values) < $window || $window <= 0) {
            return null;
        }
        $slice = array_slice($values, -$window);
        if (empty($slice)) {
            return null;
        }
        return array_sum($slice) / count($slice);
    }

    private function volatility(array $values, int $window): ?float
    {
        if (count($values) < $window + 1) {
            return null;
        }
        $slice = array_slice($values, -($window + 1));
        $returns = [];
        for ($i = 1; $i < count($slice); $i++) {
            $prev = $slice[$i - 1];
            $curr = $slice[$i];
            if ($prev <= 0 || $curr <= 0) {
                continue;
            }
            $returns[] = log($curr / $prev);
        }
        if (count($returns) < 2) {
            return null;
        }
        $mean = array_sum($returns) / count($returns);
        $variance = array_sum(array_map(static fn ($r) => ($r - $mean) ** 2, $returns)) / (count($returns) - 1);
        return sqrt($variance) * sqrt(252); // anualizada aprox
    }

    private function variationPct($close, $previous): ?float
    {
        if (!is_numeric($close) || !is_numeric($previous) || (float) $previous === 0.0) {
            return null;
        }
        return ((float) $close - (float) $previous) / (float) $previous * 100;
    }
}
