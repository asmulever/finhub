<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente para Alpha Vantage (módulo MarketData).
 * Soporta GLOBAL_QUOTE y SYMBOL_SEARCH básicos con manejo de errores y rate limit simple.
 */
final class AlphaVantageClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(Config $config)
    {
        $this->apiKey = (string) ($config->get('AlphaVantage_API-KEY') ?? $config->get('ALPHAVANTAGE_API_KEY', ''));
        $this->baseUrl = rtrim((string) $config->get('ALPHAVANTAGE_BASE_URL', 'https://www.alphavantage.co/query'), '/');
        $this->timeoutSeconds = (int) $config->get('ALPHAVANTAGE_TIMEOUT_SECONDS', 5);
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    /**
     * Cotización actual (GLOBAL_QUOTE).
     */
    public function fetchGlobalQuote(string $symbol): array
    {
        return $this->request([
            'function' => 'GLOBAL_QUOTE',
            'symbol' => $symbol,
        ]);
    }

    /**
     * Búsqueda de símbolo (SYMBOL_SEARCH).
     */
    public function searchSymbol(string $keywords): array
    {
        return $this->request([
            'function' => 'SYMBOL_SEARCH',
            'keywords' => $keywords,
        ]);
    }

    /**
     * Serie diaria (TIME_SERIES_DAILY).
     */
    public function fetchDaily(string $symbol, string $outputSize = 'compact'): array
    {
        return $this->request([
            'function' => 'TIME_SERIES_DAILY',
            'symbol' => $symbol,
            'outputsize' => $outputSize === 'full' ? 'full' : 'compact',
        ]);
    }

    public function fetchFxDaily(string $from, string $to): array
    {
        return $this->request([
            'function' => 'FX_DAILY',
            'from_symbol' => $from,
            'to_symbol' => $to,
        ]);
    }

    public function fetchSma(string $symbol, string $interval, int $timePeriod, string $seriesType = 'close'): array
    {
        return $this->request([
            'function' => 'SMA',
            'symbol' => $symbol,
            'interval' => $interval,
            'time_period' => $timePeriod,
            'series_type' => $seriesType,
        ]);
    }

    public function fetchRsi(string $symbol, string $interval, int $timePeriod, string $seriesType = 'close'): array
    {
        return $this->request([
            'function' => 'RSI',
            'symbol' => $symbol,
            'interval' => $interval,
            'time_period' => $timePeriod,
            'series_type' => $seriesType,
        ]);
    }

    public function fetchOverview(string $symbol): array
    {
        return $this->request([
            'function' => 'OVERVIEW',
            'symbol' => $symbol,
        ]);
    }

    /**
     * Ejecuta el llamado HTTP y maneja errores comunes.
     */
    private function request(array $query): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Alpha Vantage API key requerida', 503);
        }

        $query['apikey'] = $this->apiKey;
        $url = sprintf('%s?%s', $this->baseUrl, http_build_query($query, '', '&', PHP_QUERY_RFC3986));

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Error al consultar Alpha Vantage', 502);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('Alpha Vantage devolvió HTTP %d', $status), $status);
        }

        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta inválida de Alpha Vantage', 502);
        }
        // Errores específicos
        if (isset($decoded['Note']) && str_contains((string) $decoded['Note'], 'frequency')) {
            throw new \RuntimeException('Límite de frecuencia de Alpha Vantage alcanzado', 429);
        }
        if (isset($decoded['Error Message'])) {
            throw new \RuntimeException((string) $decoded['Error Message'], 502);
        }
        return $decoded;
    }
}
