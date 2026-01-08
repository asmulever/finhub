<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\Dto\StockItem;
use FinHub\Application\MarketData\Provider\FxRateProviderInterface;
use FinHub\Application\MarketData\Provider\QuoteProviderInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\MarketData\ProviderMetrics;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

final class PriceService
{
    private ?TwelveDataClient $twelveClient;
    private ?EodhdClient $eodhdClient;
    private ?\FinHub\Infrastructure\MarketData\AlphaVantageClient $alphaClient;
    private ProviderMetrics $metrics;
    private ?\FinHub\Infrastructure\MarketData\QuoteCache $quoteCache;
    private ?\FinHub\Infrastructure\MarketData\QuoteSymbolsAggregator $symbolsAggregator;
    /** @var array<string,QuoteProviderInterface> */
    private array $quoteProviders;
    private ?FxRateProviderInterface $fxRateProvider;
    /** @var array<int,string> */
    private array $providerOrder;

    public function __construct(
        ?TwelveDataClient $client,
        ?EodhdClient $eodhdClient,
        ProviderMetrics $metrics,
        ?\FinHub\Infrastructure\MarketData\QuoteCache $quoteCache = null,
        ?\FinHub\Infrastructure\MarketData\QuoteSymbolsAggregator $symbolsAggregator = null,
        array|string|null $providerOrder = null,
        ?\FinHub\Infrastructure\MarketData\AlphaVantageClient $alphaClient = null,
        array $quoteProviders = [],
        ?FxRateProviderInterface $fxRateProvider = null
    )
    {
        $this->twelveClient = $client;
        $this->eodhdClient = $eodhdClient;
        $this->metrics = $metrics;
        $this->quoteCache = $quoteCache;
        $this->symbolsAggregator = $symbolsAggregator;
        $this->providerOrder = $this->sanitizeProviderOrder($providerOrder);
        $this->alphaClient = $alphaClient;
        $this->quoteProviders = [];
        foreach ($quoteProviders as $provider) {
            if ($provider instanceof QuoteProviderInterface && $provider->isConfigured()) {
                $this->quoteProviders[$provider->name()] = $provider;
            }
        }
        $this->fxRateProvider = $fxRateProvider;
    }

    /**
     * Normaliza el orden de proveedores configurado.
     *
     * @param array<int,string>|string|null $providerOrder
     * @return array<int,string>
     */
    private function sanitizeProviderOrder(array|string|null $providerOrder): array
    {
        $default = ['twelvedata', 'eodhd', 'alphavantage'];
        $list = $default;
        if (is_string($providerOrder)) {
            $parts = preg_split('/,/', $providerOrder, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts) && !empty($parts)) {
                $list = $parts;
            }
        } elseif (is_array($providerOrder) && !empty($providerOrder)) {
            $list = $providerOrder;
        }

        $normalized = [];
        foreach ($list as $item) {
            $p = strtolower(trim((string) $item));
            if ($p === '') {
                continue;
            }
            if ($p !== 'eodhd' && $p !== 'twelvedata' && $p !== 'alphavantage') {
                continue;
            }
            $normalized[$p] = true;
        }

        $result = array_keys($normalized);
        if (empty($result)) {
            return $default;
        }
        return $result;
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

        $order = $this->resolveProviderOrder($preferred);
        $result = null;
        $sources = [];
        $errors = [];
        $providers = [];

        foreach ($order as $providerName) {
            $provider = $this->quoteProviders[$providerName] ?? null;
            if ($provider === null || $this->shouldSkipProvider($providerName, $symbol, $exchange)) {
                continue;
            }
            try {
                $quote = $provider->quote($symbol, $exchange);
                $sources[] = $providerName;
                $providers[] = ['provider' => $providerName, 'ok' => true, 'quote' => $quote];
                $this->metrics->record($providerName, true);
                if ($result === null) {
                    $result = $quote;
                }
            } catch (\Throwable $e) {
                if ($providerName === 'eodhd') {
                    $this->handleEodhdFailure($e, $this->formatEodSymbol($symbol, $exchange), $exchange);
                }
                $this->metrics->record($providerName, false);
                $providers[] = ['provider' => $providerName, 'ok' => false, 'error' => $e->getMessage()];
                $errors[] = ['provider' => $providerName, 'message' => $e->getMessage()];
            }
        }

        if ($result === null) {
            $firstError = $errors[0]['message'] ?? 'No se pudo obtener el precio';
            throw new \RuntimeException($firstError, 502);
        }

        $final = $this->buildQuoteFromSnapshot($result, $symbol);
        $final['sources'] = $sources;
        $final['cached'] = false;
        $final['providers'] = $providers;

        if ($this->quoteCache !== null) {
            $this->quoteCache->set($cacheKey, $final, 600);
        }

        return $final;
    }

    /**
     * Busca múltiples precios con cache y batch para reducir llamadas a proveedores.
     *
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>>
     */
    public function searchQuotes(array $symbols, ?string $exchange, string $preferred, bool $forceRefresh = false): array
    {
        $exchange = $exchange !== null ? strtoupper(trim($exchange)) : null;
        $preferred = in_array(strtolower($preferred), ['twelvedata', 'eodhd', 'alphavantage'], true)
            ? strtolower($preferred)
            : 'twelvedata';

        $normalized = [];
        foreach ($symbols as $symbol) {
            $s = strtoupper(trim((string) $symbol));
            if ($s === '') {
                continue;
            }
            $normalized[$s] = $s;
        }
        if (empty($normalized)) {
            throw new \RuntimeException('Parámetro s (symbols) requerido', 422);
        }

        $results = [];
        $pending = [];

        foreach ($normalized as $symbol) {
            $cacheKey = sprintf('%s|%s', $symbol, $exchange ?? '');
            if (!$forceRefresh && $this->quoteCache !== null) {
                $cached = $this->quoteCache->get($cacheKey);
                if ($cached !== null) {
                    $cached['cached'] = true;
                    $results[$symbol] = $cached;
                    continue;
                }
            }
            $pending[$symbol] = $symbol;
        }

        $batchError = null;
        if (!empty($pending)) {
            try {
                $snapshots = $this->fetchSnapshotsBulk(array_keys($pending), $exchange, $preferred);
            } catch (\Throwable $e) {
                $snapshots = [];
                $batchError = $e->getMessage();
            }

            foreach ($pending as $symbol => $_) {
                $cacheKey = sprintf('%s|%s', $symbol, $exchange ?? '');
                $snapshot = $snapshots[$symbol] ?? null;
                if ($snapshot !== null) {
                    $quote = $this->buildQuoteFromSnapshot($snapshot, $symbol);
                    if ($this->quoteCache !== null) {
                        $this->quoteCache->set($cacheKey, $quote, 600);
                    }
                    $results[$symbol] = $quote;
                    continue;
                }
                try {
                    $quote = $this->searchQuote($symbol, $exchange, $preferred, $forceRefresh);
                    $results[$symbol] = $quote;
                    if ($this->quoteCache !== null) {
                        $this->quoteCache->set($cacheKey, $quote, 600);
                    }
                } catch (\Throwable $e) {
                    $results[$symbol] = [
                        'symbol' => $symbol,
                        'error' => ['message' => $e->getMessage()],
                    ];
                }
            }
        }

        if (empty($results)) {
            $message = $batchError ?? 'No se pudieron obtener los precios solicitados';
            throw new \RuntimeException($message, 502);
        }

        return $results;
    }

    /**
     * Exposición directa de Alpha Vantage para pruebas (GLOBAL_QUOTE).
     */
    public function alphaQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $provider = $this->quoteProviders['alphavantage'] ?? null;
        if ($provider === null) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $provider->quote($symbol, null);
    }

    /**
     * Exposición directa de Alpha Vantage para pruebas (SYMBOL_SEARCH).
     */
    public function alphaSearch(string $keywords): array
    {
        $keywords = trim($keywords);
        if ($keywords === '') {
            throw new \RuntimeException('keywords requerido', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->alphaClient->searchSymbol($keywords);
    }

    /**
     * Exposición directa de Alpha Vantage para pruebas (TIME_SERIES_DAILY).
     */
    public function alphaDaily(string $symbol, string $outputSize = 'compact'): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        $size = strtolower(trim($outputSize)) === 'full' ? 'full' : 'compact';
        return $this->alphaClient->fetchDaily($symbol, $size);
    }

    public function alphaFxDaily(string $from, string $to): array
    {
        $from = strtoupper(trim($from));
        $to = strtoupper(trim($to));
        if ($from === '' || $to === '') {
            throw new \RuntimeException('from/to requeridos', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->alphaClient->fetchFxDaily($from, $to);
    }

    public function alphaSma(string $symbol, string $interval, int $timePeriod, string $seriesType = 'close'): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $int = in_array($interval, ['1min', '5min', '15min', '30min', '60min', 'daily', 'weekly', 'monthly'], true) ? $interval : 'daily';
        $series = in_array(strtolower($seriesType), ['close', 'open', 'high', 'low'], true) ? strtolower($seriesType) : 'close';
        if ($timePeriod <= 0) {
            throw new \RuntimeException('time_period debe ser > 0', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->alphaClient->fetchSma($symbol, $int, $timePeriod, $series);
    }

    public function alphaRsi(string $symbol, string $interval, int $timePeriod, string $seriesType = 'close'): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $int = in_array($interval, ['1min', '5min', '15min', '30min', '60min', 'daily', 'weekly', 'monthly'], true) ? $interval : 'daily';
        $series = in_array(strtolower($seriesType), ['close', 'open', 'high', 'low'], true) ? strtolower($seriesType) : 'close';
        if ($timePeriod <= 0) {
            throw new \RuntimeException('time_period debe ser > 0', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->alphaClient->fetchRsi($symbol, $int, $timePeriod, $series);
    }

    public function alphaOverview(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        if ($this->alphaClient === null || !$this->alphaClient->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->alphaClient->fetchOverview($symbol);
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
     * Quote directo desde Twelve Data (sin fallback).
     */
    public function twelveQuote(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $provider = $this->quoteProviders['twelvedata'] ?? null;
        if ($provider === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $provider->quote($symbol, null);
    }

    /**
     * Quotes batch desde Twelve Data.
     *
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>>
     */
    public function twelveQuotes(array $symbols): array
    {
        $provider = $this->quoteProviders['twelvedata'] ?? null;
        if ($provider === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $provider->quotes($symbols, null);
    }

    /**
     * Lista de instrumentos desde Twelve Data.
     *
     * @return array<int,array<string,mixed>>
     */
    public function twelveStocks(?string $exchange = null): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        $raw = $this->twelveClient->listStocks($exchange);
        $items = [];
        foreach ($raw as $row) {
            $symbol = $row['symbol'] ?? $row['Symbol'] ?? null;
            if ($symbol === null || trim((string) $symbol) === '') {
                continue;
            }
            $item = StockItem::fromArray([
                'symbol' => $symbol,
                'name' => $row['name'] ?? $row['Name'] ?? null,
                'currency' => $row['currency'] ?? $row['Currency'] ?? null,
                'exchange' => $row['exchange'] ?? $row['Exchange'] ?? $exchange,
                'country' => $row['country'] ?? $row['Country'] ?? null,
                'mic_code' => $row['mic_code'] ?? $row['mic'] ?? $row['micCode'] ?? null,
                'type' => $row['type'] ?? $row['Type'] ?? null,
            ]);
            $items[] = $item->toArray();
        }
        return $items;
    }

    /**
     * Uso/limites de API Twelve Data.
     */
    public function twelveUsage(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchUsage();
    }

    public function twelvePrice(string $symbol): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchPrice($symbol);
    }

    public function twelveTimeSeries(string $symbol, array $query): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchTimeSeries($symbol, $query);
    }

    public function twelveExchangeRate(string $symbol): array
    {
        if ($this->fxRateProvider !== null && $this->fxRateProvider->isConfigured()) {
            return $this->fxRateProvider->rate($symbol);
        }
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchExchangeRate($symbol);
    }

    public function twelveCurrencyConversion(string $symbol, float $amount): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchCurrencyConversion($symbol, $amount);
    }

    public function twelveMarketState(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchMarketState();
    }

    public function twelveCryptoExchanges(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchCryptocurrencyExchanges();
    }

    public function twelveInstrumentTypes(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchInstrumentTypes();
    }

    public function twelveSymbolSearch(string $keywords): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->symbolSearch($keywords);
    }

    public function twelveForexPairs(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchForexPairs();
    }

    public function twelveCryptocurrencies(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchCryptocurrencies();
    }

    public function twelveStocksByExchange(string $exchange): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchStocksByExchange($exchange);
    }

    public function twelveExchangeSchedule(string $exchange): array
    {
        throw new \RuntimeException('Endpoint no disponible en plan actual', 403);
    }

    public function twelveEarliestTimestamp(string $symbol, ?string $exchange = null): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchEarliestTimestamp($symbol, $exchange);
    }

    public function twelveTechnicalIndicator(string $function, array $params): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchTechnicalIndicator($function, $params);
    }

    public function twelveExchanges(): array
    {
        if ($this->twelveClient === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
        }
        return $this->twelveClient->fetchExchanges();
    }

    /**
     * Devuelve snapshot crudo con el proveedor usado (para Data Lake).
     */
    public function fetchSnapshot(string $symbol): array
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        $order = $this->resolveProviderOrder(null);

        foreach ($order as $providerName) {
            $provider = $this->quoteProviders[$providerName] ?? null;
            if ($provider === null || $this->shouldSkipProvider($providerName, $symbol, null)) {
                continue;
            }
            try {
                $quote = $provider->quote($symbol, null);
                $this->metrics->record($providerName, true);
                return $quote;
            } catch (\Throwable $e) {
                if ($providerName === 'eodhd') {
                    $this->handleEodhdFailure($e, $symbol, null);
                }
                $this->metrics->record($providerName, false);
            }
        }

        throw new \RuntimeException('No se pudo obtener el precio desde los proveedores configurados', 502);
    }

    /**
     * Recupera snapshots en batch, priorizando Twelve Data y usando fallback EODHD.
     *
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>> keyed por símbolo original
     */
    public function fetchSnapshotsBulk(array $symbols, ?string $exchange = null, ?string $preferred = null): array
    {
        $clean = [];
        $seen = [];
        foreach ($symbols as $symbol) {
            $s = trim((string) $symbol);
            if ($s === '') {
                continue;
            }
            $upper = strtoupper($s);
            if (isset($seen[$upper])) {
                continue;
            }
            $seen[$upper] = true;
            $clean[$s] = $upper;
        }
        if (empty($clean)) {
            throw new \RuntimeException('Símbolos requeridos', 422);
        }

        $exchangeUpper = $exchange !== null ? strtoupper(trim($exchange)) : null;
        $order = $this->resolveProviderOrder($preferred);
        $results = [];
        $pending = $clean;
        $errors = [];

        foreach ($order as $providerName) {
            if (empty($pending)) {
                break;
            }
            $pending = array_filter($pending, function ($upper) use ($providerName, $exchangeUpper) {
                return !$this->shouldSkipProvider($providerName, $upper, $exchangeUpper);
            });
            if (empty($pending)) {
                continue;
            }
            $provider = $this->quoteProviders[$providerName] ?? null;
            if ($provider === null) {
                continue;
            }

            try {
                $batchQuotes = $provider->quotes(array_keys($pending), $exchangeUpper);
                foreach ($pending as $original => $upper) {
                    $quote = $batchQuotes[$original] ?? $batchQuotes[$upper] ?? null;
                    if ($quote === null) {
                        continue;
                    }
                    $results[$original] = $quote;
                    unset($pending[$original]);
                }
                $this->metrics->record($providerName, true);
            } catch (\Throwable $e) {
                foreach ($pending as $original => $_) {
                    $errors[$original] = $e->getMessage();
                }
                if ($providerName === 'eodhd') {
                    $this->handleEodhdFailure($e, implode(',', array_values($pending)), $exchangeUpper);
                }
                $this->metrics->record($providerName, false);
            }
        }

        // 3) Fallback individual para cubrir huecos
        foreach ($pending as $original => $_) {
            try {
                $results[$original] = $this->fetchSnapshot($original);
            } catch (\Throwable $e) {
                $errors[$original] = $e->getMessage();
            }
        }

        if (empty($results) && !empty($errors)) {
            $firstError = array_values($errors)[0] ?? 'No se pudo obtener los precios solicitados';
            throw new \RuntimeException($firstError, 502);
        }

        return $results;
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }

    private function shouldSkipProvider(string $provider, string $symbol, ?string $exchange): bool
    {
        if ($this->metrics->isDisabled($provider)) {
            return true;
        }
        if ($provider === 'eodhd') {
            $sym = strtoupper(trim($symbol));
            $ex = $exchange !== null ? strtoupper(trim($exchange)) : '';
            if ($this->metrics->isNoData($provider, $sym, $ex)) {
                return true;
            }
        }
        return false;
    }

    private function handleEodhdFailure(\Throwable $exception, string $symbol, ?string $exchange): void
    {
        $msg = strtolower($exception->getMessage());
        if ($this->isQuotaError($msg)) {
            $this->metrics->disable('eodhd', $this->secondsUntilTomorrow(), 'quota_error');
        }
        if ($this->isDataAvailabilityError($msg)) {
            $this->metrics->markNoData('eodhd', $symbol, $exchange, $this->secondsUntilTomorrow());
        }
    }

    private function isDataAvailabilityError(string $message): bool
    {
        return str_contains($message, 'not found')
            || str_contains($message, 'no data')
            || str_contains($message, 'unknown symbol')
            || str_contains($message, 'invalid api call')
            || str_contains($message, '404');
    }

    private function isQuotaError(string $message): bool
    {
        return str_contains($message, '402')
            || str_contains($message, '403')
            || str_contains($message, 'quota')
            || str_contains($message, 'payment required')
            || str_contains($message, 'out of api credits')
            || str_contains($message, 'rate limit');
    }

    private function secondsUntilTomorrow(): int
    {
        $now = new \DateTimeImmutable('now');
        $tomorrow = $now->setTime(0, 0)->modify('+1 day');
        return max(60, (int) ($tomorrow->getTimestamp() - $now->getTimestamp()));
    }

    /**
     * Ordena proveedores según configuración y preferencia solicitada.
     *
     * @return array<int,string>
     */
    private function resolveProviderOrder(?string $preferred = null): array
    {
        $base = $this->providerOrder;
        $preferred = $preferred !== null ? strtolower(trim($preferred)) : null;
        if ($preferred !== null && $preferred !== '') {
            array_unshift($base, $preferred);
        }
        $seen = [];
        $ordered = [];
        foreach ($base as $provider) {
            $p = strtolower(trim((string) $provider));
            if ($p === '' || isset($seen[$p])) {
                continue;
            }
            if (!isset($this->quoteProviders[$p])) {
                continue;
            }
            if ($p !== 'twelvedata' && $p !== 'eodhd' && $p !== 'alphavantage') {
                continue;
            }
            if ($this->metrics->isDisabled($p)) {
                continue;
            }
            $seen[$p] = true;
            $ordered[] = $p;
        }
        if (empty($ordered)) {
            throw new \RuntimeException('No hay proveedores configurados', 503);
        }
        return $ordered;
    }
    private function buildQuoteFromSnapshot(array $snapshot, string $symbol): array
    {
        $source = $snapshot['source'] ?? $snapshot['provider'] ?? 'unknown';
        $quote = [
            'symbol' => $symbol,
            'name' => $snapshot['name'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'open' => $this->floatOrNull($snapshot['open'] ?? null),
            'high' => $this->floatOrNull($snapshot['high'] ?? null),
            'low' => $this->floatOrNull($snapshot['low'] ?? null),
            'close' => $this->floatOrNull($snapshot['close'] ?? null),
            'previous_close' => $this->floatOrNull($snapshot['previous_close'] ?? null),
            'asOf' => $snapshot['asOf'] ?? $snapshot['as_of'] ?? $snapshot['datetime'] ?? $snapshot['timestamp'] ?? null,
            'source' => $source,
        ];
        $quote['sources'] = [$source];
        $quote['cached'] = false;
        $quote['providers'] = [[
            'provider' => $source,
            'ok' => true,
            'quote' => [
                'symbol' => $quote['symbol'],
                'name' => $quote['name'],
                'currency' => $quote['currency'],
                'open' => $quote['open'],
                'high' => $quote['high'],
                'low' => $quote['low'],
                'close' => $quote['close'],
                'previous_close' => $quote['previous_close'],
                'asOf' => $quote['asOf'],
            ],
        ]];
        return $quote;
    }

    private function formatEodSymbol(string $symbol, ?string $exchange): string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return '';
        }
        if (str_contains($symbol, '.')) {
            return $symbol;
        }
        $exchangeUpper = $exchange !== null ? $exchange : 'US';
        return sprintf('%s.%s', $symbol, $exchangeUpper);
    }

    /**
     * Indexa respuesta de EODHD batch (real-time) por code/symbol.
     *
     * @param array<string|int,mixed> $data
     * @return array<string,array<string,mixed>>
     */
    private function indexEodhdBatch(array $data): array
    {
        $indexed = [];

        $rows = $data;
        if (isset($data['code']) || isset($data['symbol'])) {
            $rows = [$data];
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper((string) ($row['code'] ?? $row['symbol'] ?? ''));
            if ($code === '') {
                continue;
            }
            $indexed[$code] = $row;
        }

        return $indexed;
    }
}
