<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

use FinHub\Application\MarketData\PriceService;
use FinHub\Application\MarketData\TiingoService;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Portfolio\PortfolioSummaryService;

/**
 * Arma el payload de heatmap del portafolio con precios, FX y sector/industry.
 * Módulo: Portfolio (Application).
 */
final class PortfolioHeatmapService
{
    private PortfolioService $portfolioService;
    private PortfolioSummaryService $portfolioSummaryService;
    private PortfolioSectorService $sectorService;
    private PriceService $priceService;
    private TiingoService $tiingoService;
    private LoggerInterface $logger;

    public function __construct(
        PortfolioService $portfolioService,
        PortfolioSummaryService $portfolioSummaryService,
        PortfolioSectorService $sectorService,
        PriceService $priceService,
        TiingoService $tiingoService,
        LoggerInterface $logger
    ) {
        $this->portfolioService = $portfolioService;
        $this->portfolioSummaryService = $portfolioSummaryService;
        $this->sectorService = $sectorService;
        $this->priceService = $priceService;
        $this->tiingoService = $tiingoService;
        $this->logger = $logger;
    }

    /**
     * @return array<string,mixed>
     */
    public function build(int $userId): array
    {
        $baseCurrency = $this->portfolioService->getBaseCurrency($userId);
        $instruments = $this->portfolioService->listInstruments($userId);
        $uniqueSymbols = [];
        foreach ($instruments as $instrument) {
            $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $uniqueSymbols[$symbol] = true;
        }

        $quotes = $this->fetchQuotes(array_keys($uniqueSymbols));
        // Fallback: summary ya normalizado (incluye precio desde DataLake o providers)
        $summaryMap = $this->mapSummary($userId);
        $sectors = $this->sectorService->listSectorIndustry($userId);
        $sectorMap = $this->mapSectors($sectors);
        $now = new \DateTimeImmutable();
        $cutoff = $now->modify('-24 hours')->getTimestamp();

        $items = [];
        $fxMeta = ['source' => null, 'fx_at' => null];

        foreach ($instruments as $instrument) {
            $symbol = strtoupper((string) ($instrument['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $quantity = $this->floatOrNull($instrument['quantity'] ?? $instrument['qty'] ?? null);
            $quantityFallback = false;
            if ($quantity === null || $quantity <= 0) {
                $quantity = 1.0; // fallback para no dejar vacío el heatmap
                $quantityFallback = true;
                $this->logger->info('portfolio.heatmap.quantity_fallback', [
                    'symbol' => $symbol,
                    'reason' => 'missing_quantity',
                ]);
            }
            $quote = $quotes[$symbol] ?? ($summaryMap[$symbol] ?? null);
            if ($quote === null) {
                $this->logger->info('portfolio.heatmap.excluded', [
                    'symbol' => $symbol,
                    'reason' => 'missing_quote',
                ]);
                continue;
            }
            $lastPrice = $this->floatOrNull($quote['close'] ?? $quote['price'] ?? null);
            if ($lastPrice === null) {
                $lastPrice = $this->floatOrNull($summaryMap[$symbol]['close'] ?? $summaryMap[$symbol]['price'] ?? null);
            }
            $prevClose = $this->floatOrNull($quote['previous_close'] ?? $quote['previousClose'] ?? null);
            if ($prevClose === null) {
                $prevClose = $this->floatOrNull($summaryMap[$symbol]['previous_close'] ?? $summaryMap[$symbol]['previousClose'] ?? null);
            }
            if ($lastPrice === null) {
                $this->logger->info('portfolio.heatmap.excluded', [
                    'symbol' => $symbol,
                    'reason' => 'missing_price',
                ]);
                continue;
            }
            $priceAt = $quote['asOf'] ?? $quote['as_of'] ?? null;
            if ($priceAt !== null) {
                $ts = strtotime((string) $priceAt);
                if ($ts !== false && $ts < $cutoff) {
                    $this->logger->info('portfolio.heatmap.excluded', [
                        'symbol' => $symbol,
                        'reason' => 'stale_price',
                    ]);
                    continue;
                }
            }

            $instrumentCurrency = strtoupper((string) ($quote['currency'] ?? $instrument['currency'] ?? ($summaryMap[$symbol]['currency'] ?? $baseCurrency)));
            $fx = $this->resolveFx($instrumentCurrency, $baseCurrency);
            if ($fx['rate'] === null) {
                // fallback: asumir 1:1 en base
                $fx = ['rate' => 1.0, 'source' => 'assumed', 'at' => null];
                $this->logger->info('portfolio.heatmap.fx.assumed', [
                    'symbol' => $symbol,
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
            $sectorRow = $sectorMap[$symbol] ?? ['sector' => 'Sin sector', 'industry' => 'Sin industry'];

            $items[] = [
                'symbol' => $symbol,
                'name' => $instrument['name'] ?? $instrument['nombre'] ?? $symbol,
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

        return [
            'account_id' => (string) $userId,
            'base_currency' => $baseCurrency,
            'as_of' => $asOf,
            'fx_source' => $fxMeta['source'],
            'fx_at' => $fxMeta['fx_at'],
            'groups' => array_values($groups),
        ];
    }

    /**
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>>
     */
    private function fetchQuotes(array $symbols): array
    {
        if (empty($symbols)) {
            return [];
        }
        try {
            return $this->priceService->searchQuotes($symbols, null, 'twelvedata', false);
        } catch (\Throwable $e) {
            $this->logger->info('portfolio.heatmap.quotes_failed', ['message' => $e->getMessage()]);
        }

        $results = [];
        foreach ($symbols as $symbol) {
            try {
                $results[$symbol] = $this->priceService->searchQuote($symbol, null, 'twelvedata', false);
            } catch (\Throwable $e) {
                $this->logger->info('portfolio.heatmap.quote_failed', [
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return $results;
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
     * @param array<int,array<string,mixed>> $rows
     * @return array<string,array{sector:string,industry:string}>
     */
    private function mapSectors(array $rows): array
    {
        $map = [];
        foreach ($rows as $row) {
            $symbol = strtoupper((string) ($row['symbol'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $map[$symbol] = [
                'sector' => $row['sector'] ?? 'Sin sector',
                'industry' => $row['industry'] ?? 'Sin industry',
            ];
        }
        return $map;
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
                $symbol = strtoupper((string) ($row['symbol'] ?? ''));
                if ($symbol === '') {
                    continue;
                }
                $map[$symbol] = [
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
    private function resolveFx(string $instrumentCurrency, string $baseCurrency): array
    {
        $instrumentCurrency = strtoupper(trim($instrumentCurrency));
        $baseCurrency = strtoupper(trim($baseCurrency));
        if ($instrumentCurrency === '' || $baseCurrency === '') {
            return ['rate' => null, 'source' => null, 'at' => null];
        }
        if ($instrumentCurrency === $baseCurrency) {
            return ['rate' => 1.0, 'source' => 'identity', 'at' => null];
        }
        $pair = sprintf('%s/%s', $baseCurrency, $instrumentCurrency);
        try {
            $fx = $this->priceService->twelveExchangeRate($pair);
            $rate = $this->floatOrNull($fx['rate'] ?? $fx['value'] ?? $fx['price'] ?? null);
            if ($rate !== null) {
                $at = $fx['timestamp'] ?? $fx['datetime'] ?? null;
                return ['rate' => $rate, 'source' => 'twelvedata', 'at' => is_string($at) ? $at : null];
            }
        } catch (\Throwable $e) {
            $this->logger->info('portfolio.heatmap.fx.twelvedata_failed', [
                'pair' => $pair,
                'message' => $e->getMessage(),
            ]);
        }

        try {
            $ticker = strtolower($baseCurrency . $instrumentCurrency);
            $resp = $this->tiingoService->fxPrices([$ticker]);
            if (is_array($resp) && !empty($resp)) {
                $row = is_array(reset($resp)) ? reset($resp) : null;
                $rate = $this->floatOrNull($row['midPrice'] ?? $row['rate'] ?? $row['close'] ?? $row['price'] ?? null);
                if ($rate !== null) {
                    $at = $row['quoteTimestamp'] ?? $row['timestamp'] ?? null;
                    return ['rate' => $rate, 'source' => 'tiingo', 'at' => is_string($at) ? $at : null];
                }
            }
        } catch (\Throwable $e) {
            $this->logger->info('portfolio.heatmap.fx.tiingo_failed', [
                'pair' => $pair,
                'message' => $e->getMessage(),
            ]);
        }

        return ['rate' => null, 'source' => null, 'at' => null];
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
}
