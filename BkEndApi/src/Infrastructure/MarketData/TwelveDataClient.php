<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

final class TwelveDataClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;
    private ?array $lastResponseHeaders = null;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.twelvedata.com', int $timeoutSeconds = 5)
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->timeoutSeconds = $timeoutSeconds > 0 ? $timeoutSeconds : 5;
    }

    /**
     * Recupera un quote completo para el símbolo indicado.
     *
     * @throws \RuntimeException cuando la API externa responde error o el payload no es válido.
     */
    public function fetchQuote(string $symbol): array
    {
        $params = [
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
        ];
        return $this->request('quote', $params);
    }

    /**
     * Recupera precios para múltiples símbolos en una sola llamada.
     *
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>>
     */
    public function fetchQuotes(array $symbols): array
    {
        $symbols = array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $symbols), static fn ($s) => $s !== ''));
        if (empty($symbols)) {
            throw new \RuntimeException('Símbolos requeridos para Twelve Data', 422);
        }
        $params = [
            'symbol' => implode(',', $symbols),
            'apikey' => $this->apiKey,
        ];
        $response = $this->request('price', $params);

        if (isset($response['data']) && is_array($response['data'])) {
            $response = $response['data'];
        }
        if (!is_array($response)) {
            throw new \RuntimeException('Respuesta inválida desde Twelve Data (batch)', 502);
        }

        $result = [];
        foreach ($response as $key => $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbolKey = strtoupper((string) ($row['symbol'] ?? $key));
            if ($symbolKey === '') {
                continue;
            }
            $result[$symbolKey] = $row;
        }

        if (empty($result) && isset($response['symbol']) && is_array($response)) {
            $symbolKey = strtoupper((string) ($response['symbol'] ?? ''));
            if ($symbolKey !== '') {
                $result[$symbolKey] = $response;
            }
        }

        if (empty($result)) {
            throw new \RuntimeException('Respuesta vacía desde Twelve Data (batch)', 502);
        }

        return $result;
    }

    /**
     * Precio simple (valor único).
     */
    public function fetchPrice(string $symbol): array
    {
        $params = [
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
        ];
        return $this->request('price', $params);
    }

    /**
     * Serie de tiempo OHLCV (histórica o intradía).
     */
    public function fetchTimeSeries(string $symbol, array $query = []): array
    {
        $params = array_merge([
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
            'interval' => $query['interval'] ?? '1day',
            'outputsize' => $query['outputsize'] ?? 'compact',
        ], $query);
        return $this->request('time_series', $params);
    }

    /**
     * Tipo de cambio (forex/cripto).
     */
    public function fetchExchangeRate(string $symbol): array
    {
        $params = [
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
        ];
        return $this->request('exchange_rate', $params);
    }

    /**
     * Conversión de monto entre monedas.
     */
    public function fetchCurrencyConversion(string $symbol, float $amount): array
    {
        $params = [
            'symbol' => $symbol,
            'amount' => $amount,
            'apikey' => $this->apiKey,
        ];
        return $this->request('currency_conversion', $params);
    }

    /**
     * Estado de mercado (abierto/cerrado).
     */
    public function fetchMarketState(): array
    {
        return $this->request('market_state', ['apikey' => $this->apiKey]);
    }

    /**
     * Lista de exchanges de cripto.
     */
    public function fetchCryptocurrencyExchanges(): array
    {
        return $this->request('cryptocurrency_exchanges', ['apikey' => $this->apiKey]);
    }

    /**
     * Tipos de instrumento disponibles.
     */
    public function fetchInstrumentTypes(): array
    {
        return $this->request('instrument_type', ['apikey' => $this->apiKey]);
    }

    /**
     * Búsqueda de símbolos.
     */
    public function symbolSearch(string $keywords): array
    {
        $params = [
            'symbol' => $keywords,
            'apikey' => $this->apiKey,
        ];
        return $this->request('symbol_search', $params);
    }

    /**
     * Listas de forex pairs.
     */
    public function fetchForexPairs(): array
    {
        return $this->request('forex_pairs', ['apikey' => $this->apiKey]);
    }

    /**
     * Lista de criptomonedas.
     */
    public function fetchCryptocurrencies(): array
    {
        return $this->request('cryptocurrencies', ['apikey' => $this->apiKey]);
    }

    /**
     * Lista de acciones por exchange.
     */
    public function fetchStocksByExchange(string $exchange): array
    {
        $params = [
            'exchange' => $exchange,
            'apikey' => $this->apiKey,
        ];
        return $this->request('stocks', $params);
    }

    /**
     * Primer timestamp disponible para un instrumento.
     */
    public function fetchEarliestTimestamp(string $symbol, ?string $exchange = null): array
    {
        $params = [
            'symbol' => $symbol,
            'apikey' => $this->apiKey,
        ];
        if ($exchange) {
            $params['exchange'] = $exchange;
        }
        return $this->request('earliest_timestamp', $params);
    }

    /**
     * Indicadores técnicos (wrapper genérico).
     *
     * @param array<string,mixed> $params
     */
    public function fetchTechnicalIndicator(string $function, array $params): array
    {
        $query = array_merge([
            'symbol' => $params['symbol'] ?? '',
            'interval' => $params['interval'] ?? '1day',
            'apikey' => $this->apiKey,
        ], $params);
        return $this->request($function, $query);
    }


    /**
     * Lista los tickers disponibles.
     */
    public function listStocks(?string $exchange = null): array
    {
        $params = [
            'apikey' => $this->apiKey,
            'mic_code' => $exchange ? strtoupper($exchange) : 'XBUE',
        ];
        $response = $this->request('stocks', $params);
        $data = $response['data'] ?? $response;
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta inválida desde Twelve Data (stocks)', 502);
        }
        return $data;
    }

    /**
     * Recupera información de uso de la cuenta (requests restantes).
     */
    public function fetchUsage(): array
    {
        $response = $this->request('api_usage', ['apikey' => $this->apiKey]);

        // Límites diarios
        $dailyLimit = $this->extractFirstInt([
            $response['plan_daily_limit'] ?? null,
            $response['daily_usage']['limit'] ?? null,
        ]);
        $dailyUsed = $this->extractFirstInt([
            $response['daily_usage'] ?? null,
            $response['daily_usage']['used'] ?? null,
        ]);
        if ($dailyUsed === null && isset($response['daily_usage']) && is_numeric($response['daily_usage'])) {
            $dailyUsed = (int) $response['daily_usage'];
        }
        $dailyRemaining = null;
        if ($dailyLimit !== null && $dailyUsed !== null) {
            $dailyRemaining = max(0, $dailyLimit - $dailyUsed);
        }

        // Ventana por minuto (opcional)
        $perMinuteLimit = $this->extractFirstInt([
            $response['plan_limit'] ?? null,
            $response['current_usage']['limit'] ?? null,
        ]);
        $perMinuteUsed = $this->extractFirstInt([
            $response['current_usage'] ?? null,
            $response['current_usage']['used'] ?? null,
            $response['current_usage']['value'] ?? null,
        ]);
        if ($perMinuteUsed === null && isset($response['current_usage']) && is_numeric($response['current_usage'])) {
            $perMinuteUsed = (int) $response['current_usage'];
        }
        $perMinuteRemaining = null;
        if ($perMinuteLimit !== null && $perMinuteUsed !== null) {
            $perMinuteRemaining = max(0, $perMinuteLimit - $perMinuteUsed);
        }

        return [
            'limit' => $dailyLimit,
            'remaining' => $dailyRemaining,
            'used' => $dailyUsed,
            'per_minute_limit' => $perMinuteLimit,
            'per_minute_remaining' => $perMinuteRemaining,
            'per_minute_used' => $perMinuteUsed,
            'headers' => $this->lastResponseHeaders,
            'raw' => $response,
        ];
    }

    /**
     * Ejecuta la llamada HTTP y devuelve el payload JSON decodificado.
     */
    private function request(string $endpoint, array $query): array
    {
        $url = sprintf('%s/%s?%s', $this->baseUrl, ltrim($endpoint, '/'), http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $responseHeaders = [];
        $rawBody = $this->httpGet($url, $responseHeaders);
        $this->lastResponseHeaders = $responseHeaders;
        $decoded = json_decode($rawBody, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta inválida desde Twelve Data', 502);
        }

        if (($decoded['status'] ?? '') === 'error') {
            $status = (int) ($decoded['code'] ?? 502);
            if ($status < 400 || $status >= 600) {
                $status = 502;
            }
            throw new \RuntimeException($decoded['message'] ?? 'Error desde Twelve Data', $status);
        }

        return $decoded;
    }

    private function extractFirstInt(array $candidates): ?int
    {
        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (int) $value;
            }
        }
        return null;
    }

    /**
     * Realiza un GET usando cURL si está disponible, o stream_context como alternativa.
     */
    private function httpGet(string $url, ?array &$responseHeaders = null): string
    {
        $responseHeaders = [];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $header) use (&$responseHeaders): int {
                $len = strlen($header);
                $parts = explode(':', $header, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            });
            $body = curl_exec($ch);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new \RuntimeException('No se pudo conectar a Twelve Data: ' . $error, 502);
            }
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            if ($status >= 400) {
                throw new \RuntimeException('Twelve Data devolvió código HTTP ' . $status, $status);
            }
            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $headerLine) {
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
            }
        }
        if ($body === false) {
            $statusLine = $http_response_header[0] ?? 'HTTP error';
            $code = 502;
            if (preg_match('#HTTP/[0-9.]+\\s+(\\d+)#', $statusLine, $matches)) {
                $code = (int) $matches[1];
            }
            throw new \RuntimeException('No se pudo conectar a Twelve Data: ' . $statusLine, $code);
        }
        return (string) $body;
    }
}
