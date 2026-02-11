<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Portfolio\PortfolioSummaryService;
use FinHub\Application\Cache\CacheInterface;

/**
 * Arma el payload de heatmap del portafolio con precios, FX y sector/industry.
 * Módulo: Portfolio (Application).
 */
final class PortfolioHeatmapService
{
    private const CACHE_TTL = 120; // segundos

    private PortfolioService $portfolioService;
    private PortfolioSummaryService $portfolioSummaryService;
    private LoggerInterface $logger;
    private CacheInterface $cache;

    public function __construct(
        PortfolioService $portfolioService,
        PortfolioSummaryService $portfolioSummaryService,
        LoggerInterface $logger,
        CacheInterface $cache
    ) {
        $this->portfolioService = $portfolioService;
        $this->portfolioSummaryService = $portfolioSummaryService;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $userId): array
    {
        $baseCurrency = $this->portfolioService->getBaseCurrency($userId);
        $instruments = $this->portfolioService->listInstruments($userId);
        $signature = $this->hashInstruments($instruments);
        $cacheKey = sprintf('portfolio:heatmap:%d:%s:%s', $userId, $baseCurrency ?: 'BASE', $signature);
        $cached = $this->cache->get($cacheKey, null);
        if (is_array($cached)) {
            return $cached;
        }
        $uniqueSymbols = [];
        foreach ($instruments as $instrument) {
            $especie = strtoupper((string) ($instrument['especie'] ?? $instrument['symbol'] ?? ''));
            if ($especie === '') {
                continue;
            }
            $uniqueSymbols[$especie] = true;
        }

        $summaryMap = $this->mapSummary($userId);
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify('-24 hours')->getTimestamp();

        $items = [];
        $fxMeta = ['source' => null, 'fx_at' => null];

        foreach ($instruments as $instrument) {
            $especie = strtoupper((string) ($instrument['especie'] ?? $instrument['symbol'] ?? ''));
            if ($especie === '') {
                continue;
            }
            $symbol = $this->symbolBase($especie);
            $quantity = $this->floatOrNull($instrument['quantity'] ?? $instrument['qty'] ?? null);
            $quantityFallback = false;
            if ($quantity === null || $quantity <= 0) {
                $quantity = 1.0; // fallback para no dejar vacío el heatmap
                $quantityFallback = true;
                $this->logger->info('portfolio.heatmap.quantity_fallback', [
                    'symbol' => $especie,
                    'reason' => 'missing_quantity',
                ]);
            }
            $quote = $summaryMap[$especie] ?? null;
            if ($quote === null) {
                $this->logger->info('portfolio.heatmap.excluded', [
                    'symbol' => $especie,
                    'reason' => 'missing_quote',
                ]);
                continue;
            }
            $lastPrice = $this->floatOrNull($quote['close'] ?? $quote['price'] ?? null);
            $prevClose = $this->floatOrNull($quote['previous_close'] ?? $quote['previousClose'] ?? null);
            if ($lastPrice === null) {
                $this->logger->info('portfolio.heatmap.excluded', [
                    'symbol' => $especie,
                    'reason' => 'missing_price',
                ]);
                continue;
            }
            $priceAt = $quote['asOf'] ?? $quote['as_of'] ?? null;
            if ($priceAt !== null) {
                $ts = strtotime((string) $priceAt);
                if ($ts !== false && $ts < $cutoff) {
                    $this->logger->info('portfolio.heatmap.excluded', [
                        'symbol' => $especie,
                        'reason' => 'stale_price',
                    ]);
                    continue;
                }
            }

            $instrumentCurrency = strtoupper((string) ($quote['currency'] ?? $instrument['currency'] ?? $baseCurrency));
            $fx = $this->resolveFxAssumed($instrumentCurrency, $baseCurrency);
            if ($fx['rate'] === null) {
                // fallback: asumir 1:1 en base
                $fx = ['rate' => 1.0, 'source' => 'assumed', 'at' => null];
                $this->logger->info('portfolio.heatmap.fx.assumed', [
                    'symbol' => $especie,
                    'instrument_currency' => $instrumentCurrency,
                    'base_currency' => $baseCurrency,
                ]);
            }
            if ($fxMeta['source'] === null && $fx['source'] !== null) {
                $fxMeta['source'] = $fx['source'];
                $fxMeta['fx_at'] = $fx['at'];
            }

            $marketValueBase = $quantity * $lastPrice * $fx['rate'];
            $changePct = ($prevClose !== null && $prevClose != 0.0) ? (($lastPrice / $prevClose) - 1) * 100 : 0.0;
            $sectorRow = ['sector' => 'Sin sector', 'industry' => 'Sin industry'];

            $items[] = [
                'symbol' => $symbol,
                'especie' => $especie,
                'name' => $instrument['name'] ?? $instrument['nombre'] ?? $especie,
                'price' => $lastPrice,
                'currency' => $instrumentCurrency !== '' ? $instrumentCurrency : null,
                'market_value' => $marketValueBase,
                'change_pct_d' => $changePct,
                'price_at' => $priceAt,
                'sector' => $sectorRow['sector'],
                'industry' => $sectorRow['industry'],
                'fx_source' => $fx['source'],
                'fx_at' => $fx['at'],
                'quantity_fallback' => $quantityFallback,
            ];
        }

        if (empty($items)) {
            return [
                'account_id' => (string) $userId,
                'base_currency' => $baseCurrency,
                'as_of' => $now->format(DATE_ATOM),
                'fx_source' => $fxMeta['source'],
                'fx_at' => $fxMeta['fx_at'],
                'groups' => [],
            ];
        }

        $total = array_sum(array_map(static fn ($i) => $i['market_value'], $items));
        if ($total > 0) {
            foreach ($items as &$item) {
                $item['weight_pct'] = ($item['market_value'] / $total) * 100;
            }
            unset($item);
        }

        $groups = [];
        foreach ($items as $item) {
            $sector = $item['sector'] ?? 'Sin sector';
            $industry = $item['industry'] ?? 'Sin industry';
            $key = sprintf('%s|%s', $sector, $industry);
            if (!isset($groups[$key])) {
                $groups[$key] = [
                    'sector' => $sector,
                    'industry' => $industry,
                    'items' => [],
                ];
            }
            $groups[$key]['items'][] = [
                'symbol' => $item['symbol'],
                'name' => $item['name'],
                'price' => $item['price'],
                'currency' => $item['currency'],
                'market_value' => $item['market_value'],
                'weight_pct' => $item['weight_pct'] ?? null,
                'change_pct_d' => $item['change_pct_d'],
                'price_at' => $item['price_at'],
            ];
        }

        $asOf = $this->maxAsOf($items) ?? $now->format(DATE_ATOM);

        $result = [
            'account_id' => (string) $userId,
            'base_currency' => $baseCurrency,
            'as_of' => $asOf,
            'fx_source' => $fxMeta['source'],
            'fx_at' => $fxMeta['fx_at'],
            'groups' => array_values($groups),
        ];
        $this->cache->set($cacheKey, $result, self::CACHE_TTL);
        return $result;
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function maxAsOf(array $items): ?string
    {
        $timestamps = [];
        foreach ($items as $item) {
            $asOf = $item['price_at'] ?? null;
            if (!is_string($asOf) || trim($asOf) === '') {
                continue;
            }
            $ts = strtotime($asOf);
            if ($ts !== false) {
                $timestamps[] = $ts;
            }
        }
        if (empty($timestamps)) {
            return null;
        }
        return (new \DateTimeImmutable('@' . max($timestamps)))->format(DATE_ATOM);
    }


    /**
     * Mapea summary (precio, prev close, currency) por símbolo.
     *
     * @return array<string,array<string,mixed>>
     */
    private function mapSummary(int $userId): array
    {
        $map = [];
        try {
            $summary = $this->portfolioSummaryService->summaryForUser($userId);
            foreach ($summary as $row) {
                $especie = strtoupper((string) ($row['especie'] ?? $row['symbol'] ?? ''));
                if ($especie === '') {
                    continue;
                }
                $map[$especie] = [
                    'close' => $row['price'] ?? $row['close'] ?? null,
                    'previous_close' => $row['previous_close'] ?? $row['previousClose'] ?? null,
                    'currency' => $row['currency'] ?? null,
                    'price_at' => $row['as_of'] ?? null,
                ];
            }
        } catch (\Throwable $e) {
            $this->logger->info('portfolio.heatmap.summary_fallback_failed', ['message' => $e->getMessage()]);
        }
        return $map;
    }

    /**
     * @return array{rate:?float,source:?string,at:?string}
     */
    private function resolveFxAssumed(string $instrumentCurrency, string $baseCurrency): array
    {
        $instrumentCurrency = strtoupper(trim($instrumentCurrency));
        $baseCurrency = strtoupper(trim($baseCurrency));
        if ($instrumentCurrency === '' || $baseCurrency === '') {
            return ['rate' => null, 'source' => null, 'at' => null];
        }
        if ($instrumentCurrency === $baseCurrency) {
            return ['rate' => 1.0, 'source' => 'identity', 'at' => null];
        }
        return ['rate' => 1.0, 'source' => 'assumed', 'at' => null];
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

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @param array<int,array<string,mixed>> $instruments
     */
    private function hashInstruments(array $instruments): string
    {
        if (empty($instruments)) {
            return 'empty';
        }
        return substr(hash('sha256', json_encode($instruments)), 0, 16);
    }
}
