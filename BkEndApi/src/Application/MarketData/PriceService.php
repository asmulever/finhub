<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\Dto\StockItem;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\MarketData\ProviderMetrics;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

final class PriceService
{
    private ?TwelveDataClient $twelveClient;
    private ?EodhdClient $eodhdClient;
    private ProviderMetrics $metrics;
    private ?\FinHub\Infrastructure\MarketData\QuoteCache $quoteCache;
    private ?\FinHub\Infrastructure\MarketData\QuoteSymbolsAggregator $symbolsAggregator;

    public function __construct(?TwelveDataClient $client, ?EodhdClient $eodhdClient, ProviderMetrics $metrics, ?\FinHub\Infrastructure\MarketData\QuoteCache $quoteCache = null, ?\FinHub\Infrastructure\MarketData\QuoteSymbolsAggregator $symbolsAggregator = null)
    {
        $this->twelveClient = $client;
        $this->eodhdClient = $eodhdClient;
        $this->metrics = $metrics;
        $this->quoteCache = $quoteCache;
        $this->symbolsAggregator = $symbolsAggregator;
    }

    /**
     * Devuelve el quote de precio normalizado para un símbolo.
     */
    public function getPrice(PriceRequest $request): array
    {
        $snapshot = $this->fetchSnapshot($request->getSymbol());
        $close = $this->floatOrNull($snapshot['close']);
        if ($close === null) {
            throw new \RuntimeException('Precio no disponible para el símbolo solicitado', 502);
        }

        return [
            'symbol' => $snapshot['symbol'],
            'name' => $snapshot['name'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'close' => $close,
            'open' => $this->floatOrNull($snapshot['open'] ?? null),
            'high' => $this->floatOrNull($snapshot['high'] ?? null),
            'low' => $this->floatOrNull($snapshot['low'] ?? null),
            'previous_close' => $this->floatOrNull($snapshot['previous_close'] ?? null),
            'asOf' => $snapshot['as_of'] ?? null,
            'source' => $snapshot['source'] ?? null,
        ];
    }

    /**
     * Busca precio directo con preferencia/fallback entre EODHD y Twelve Data, con cache de 1 día.
     *
     * @return array{symbol:string,name:?(string),currency:?(string),open:?float,high:?float,low:?float,close:?float,previous_close:?float,asOf:mixed,source:string,sources:array<string>,cached:bool,providers:array<int,array{provider:string,ok:bool,quote?:array,error?:string}>}
     */
    public function searchQuote(string $symbol, ?string $exchange, string $preferred, bool $forceRefresh = false): array
    {
        $symbol = strtoupper(trim($symbol));
        $exchange = $exchange !== null ? strtoupper(trim($exchange)) : null;
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        $cacheKey = sprintf('%s|%s', $symbol, $exchange ?? '');
        if (!$forceRefresh && $this->quoteCache !== null) {
            $cached = $this->quoteCache->get($cacheKey);
            if ($cached !== null) {
                $cached['cached'] = true;
                return $cached;
            }
        }

        $order = strtolower($preferred) === 'twelvedata' ? ['twelvedata', 'eodhd'] : ['eodhd', 'twelvedata'];
        $result = null;
        $sources = [];
        $errors = [];
        $providers = [];

        // Resolver variantes de símbolo
        $symbolUpper = $symbol;
        $exchangeUpper = $exchange !== null ? $exchange : (str_contains($symbolUpper, '.') ? explode('.', $symbolUpper, 2)[1] : 'US');
        $symbolWithEx = str_contains($symbolUpper, '.') ? $symbolUpper : sprintf('%s.%s', $symbolUpper, $exchangeUpper);
        $baseSymbol = explode('.', $symbolUpper, 2)[0]; // AAPL.BA -> AAPL

        foreach ($order as $provider) {
            try {
                if ($provider === 'eodhd') {
                    $quote = $this->fetchEodhdQuoteNormalized($symbolWithEx, $symbolUpper);
                } else {
                    $quote = $this->fetchTwelveQuoteNormalized($baseSymbol, $symbolUpper);
                }
                $sources[] = $provider;
                $providers[] = ['provider' => $provider, 'ok' => true, 'quote' => $quote];
                if ($result === null) {
                    $result = $quote;
                }
            } catch (\Throwable $e) {
                $providers[] = ['provider' => $provider, 'ok' => false, 'error' => $e->getMessage()];
                $errors[] = ['provider' => $provider, 'message' => $e->getMessage()];
            }
        }

        if ($result === null) {
            $firstError = $errors[0]['message'] ?? 'No se pudo obtener el precio';
            throw new \RuntimeException($firstError, 502);
        }

        $result['sources'] = $sources;
        $result['cached'] = false;
        $result['providers'] = $providers;

        if ($this->quoteCache !== null) {
            $this->quoteCache->set($cacheKey, $result, 86400);
        }

        return $result;
    }

    /**
     * Devuelve lista de símbolos unificada por exchange con flags de proveedor.
     *
     * @return array<int,array{symbol:string,name:?string,exchange:?string,currency:?string,type:?string,mic_code:?string,in_eodhd:bool,in_twelvedata:bool}>
     */
    public function listSymbols(string $exchange): array
    {
        if ($this->symbolsAggregator === null) {
            throw new \RuntimeException('Agregador de símbolos no disponible', 503);
        }
        return $this->symbolsAggregator->listSymbols($exchange);
    }

    /**
     * Devuelve la lista de tickers disponibles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStocks(string $exchange = 'US'): array
    {
        if ($this->eodhdClient === null) {
            throw new \RuntimeException('Servicio de EODHD no configurado (falta API key)', 503);
        }
        $raw = $this->eodhdClient->fetchExchangeSymbols($exchange);
        $items = [];
        foreach ($raw as $row) {
            $symbol = $row['Code'] ?? $row['symbol'] ?? null;
            if (!$symbol) {
                continue;
            }
            $item = StockItem::fromArray([
                'symbol' => $symbol,
                'name' => $row['Name'] ?? $row['name'] ?? null,
                'currency' => $row['Currency'] ?? $row['currency'] ?? null,
                'exchange' => $row['Exchange'] ?? $exchange,
                'country' => $row['Country'] ?? null,
                'mic_code' => $row['OperatingMIC'] ?? $row['mic_code'] ?? null,
                'type' => $row['Type'] ?? $row['type'] ?? null,
            ]);
            $items[] = $item->toArray();
        }
        return $items;
    }

    /**
     * Devuelve snapshot crudo con el proveedor usado (para Data Lake).
     */
    public function fetchSnapshot(string $symbol): array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        // 1) Twelve Data
        if ($this->twelveClient !== null) {
            try {
                $quote = $this->twelveClient->fetchQuote($symbol);
                $this->metrics->record('twelvedata', true);
                return $this->normalizeTwelveDataQuote($quote, $symbol);
            } catch (\Throwable $e) {
                $this->metrics->record('twelvedata', false);
            }
        }

        // 2) Fallback EODHD
        if ($this->eodhdClient !== null) {
            try {
                $quote = $this->eodhdClient->fetchLive($symbol);
                $this->metrics->record('eodhd', true);
                return $this->normalizeEodhdQuote($quote, $symbol);
            } catch (\Throwable $e) {
                $this->metrics->record('eodhd', false);
                // intentar EOD como último recurso
                try {
                    $eod = $this->eodhdClient->fetchEod($symbol);
                    $this->metrics->record('eodhd', true);
                    return $this->normalizeEodhdEod($eod, $symbol);
                } catch (\Throwable $e2) {
                    $this->metrics->record('eodhd', false);
                }
            }
        }

        throw new \RuntimeException('No se pudo obtener el precio desde los proveedores configurados', 502);
    }

    private function normalizeTwelveDataQuote(array $quote, string $symbol): array
    {
        return [
            'symbol' => $quote['symbol'] ?? $symbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'close' => $quote['close'] ?? $quote['price'] ?? null,
            'open' => $quote['open'] ?? null,
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'previous_close' => $quote['previous_close'] ?? null,
            'as_of' => $quote['datetime'] ?? ($quote['timestamp'] ?? null),
            'provider' => 'twelvedata',
            'source' => 'twelvedata',
            'payload' => $quote,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
            'source' => 'twelvedata',
        ];
    }

    private function normalizeEodhdQuote(array $quote, string $symbol): array
    {
        $close = $quote['close'] ?? $quote['price'] ?? $quote['last'] ?? null;
        $asOf = $quote['timestamp'] ?? $quote['last_update'] ?? $quote['datetime'] ?? null;
        return [
            'symbol' => $quote['code'] ?? $quote['symbol'] ?? $symbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'close' => $close,
            'open' => $quote['open'] ?? null,
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'previous_close' => $quote['previousClose'] ?? $quote['previous_close'] ?? null,
            'as_of' => $asOf,
            'provider' => 'eodhd',
            'source' => 'eodhd',
            'payload' => $quote,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function normalizeEodhdEod(array $eod, string $symbol): array
    {
        if (isset($eod[0]) && is_array($eod[0])) {
            $latest = $eod[0];
        } elseif (is_array($eod)) {
            $latest = $eod;
        } else {
            throw new \RuntimeException('Respuesta inválida de EODHD (EOD)', 502);
        }
        return [
            'symbol' => $latest['code'] ?? $symbol,
            'name' => $latest['name'] ?? null,
            'currency' => $latest['currency'] ?? null,
            'close' => $latest['close'] ?? null,
            'open' => $latest['open'] ?? null,
            'high' => $latest['high'] ?? null,
            'low' => $latest['low'] ?? null,
            'previous_close' => $latest['previousClose'] ?? $latest['previous_close'] ?? null,
            'as_of' => $latest['date'] ?? $latest['datetime'] ?? null,
            'provider' => 'eodhd',
            'source' => 'eodhd',
            'payload' => $eod,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    /**
     * Obtiene quote de EODHD según sufijo: .BA/.US -> us-quote-delayed; resto requiere exchange explícito.
     */
    private function fetchEodhdQuoteNormalized(string $symbolWithExchange, string $requestedSymbol): array
    {
        if ($this->eodhdClient === null) {
            throw new \RuntimeException('Servicio EODHD no configurado', 503);
        }
        $symbolWithEx = strtoupper($symbolWithExchange);
        $useUsQuote = str_ends_with($symbolWithEx, '.US') || str_ends_with($symbolWithEx, '.BA');

        $raw = $useUsQuote
            ? $this->eodhdClient->fetchUsQuoteDelayed($symbolWithEx)
            : $this->eodhdClient->fetchEod($symbolWithEx);

        if (isset($raw[0]) && is_array($raw[0])) {
            $raw = $raw[0];
        }
        $normalized = $this->normalizeEodhdQuote($raw, $symbolWithEx);
        return [
            'symbol' => $requestedSymbol,
            'name' => $normalized['name'],
            'currency' => $normalized['currency'] ?? null,
            'open' => $this->floatOrNull($normalized['open']),
            'high' => $this->floatOrNull($normalized['high']),
            'low' => $this->floatOrNull($normalized['low']),
            'close' => $this->floatOrNull($normalized['close']),
            'previous_close' => $this->floatOrNull($normalized['previous_close'] ?? null),
            'asOf' => $normalized['as_of'] ?? $normalized['asOf'] ?? null,
            'source' => 'eodhd',
        ];
    }

    /**
     * Obtiene quote de Twelve Data con normalización.
     */
    private function fetchTwelveQuoteNormalized(string $symbolForQuery, string $requestedSymbol): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        $raw = $this->twelveClient->fetchQuote($symbolForQuery);
        $normalized = $this->normalizeTwelveDataQuote($raw, $symbolForQuery);
        return [
            'symbol' => $requestedSymbol,
            'name' => $normalized['name'],
            'currency' => $normalized['currency'] ?? null,
            'open' => $this->floatOrNull($normalized['open']),
            'high' => $this->floatOrNull($normalized['high']),
            'low' => $this->floatOrNull($normalized['low']),
            'close' => $this->floatOrNull($normalized['close']),
            'previous_close' => $this->floatOrNull($normalized['previous_close'] ?? null),
            'asOf' => $normalized['as_of'] ?? $normalized['asOf'] ?? null,
            'source' => 'twelvedata',
        ];
    }
}
