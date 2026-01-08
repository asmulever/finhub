<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente para Tiingo (plan free) cubriendo endpoints principales de demo.
 */
final class TiingoClient
{
    private string $token;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(Config $config)
    {
        $this->token = trim((string) $config->get('TIINGO_API_TOKEN', ''));
        $this->baseUrl = rtrim((string) $config->get('TIINGO_BASE_URL', 'https://api.tiingo.com'), '/');
        $this->timeoutSeconds = max(3, (int) $config->get('TIINGO_TIMEOUT_SECONDS', 5));
    }

    public function fetchIexTops(array $tickers): array
    {
        $symbols = $this->normalizeSymbols($tickers);
        if (empty($symbols)) {
            throw new \RuntimeException('tickers requeridos para IEX Tops', 422);
        }
        return $this->request('/iex', ['tickers' => implode(',', $symbols)]);
    }

    public function fetchIexLast(array $tickers): array
    {
        $symbols = $this->normalizeSymbols($tickers);
        if (empty($symbols)) {
            throw new \RuntimeException('tickers requeridos para IEX Last', 422);
        }
        return $this->request('/iex/last', ['tickers' => implode(',', $symbols)]);
    }

    public function fetchDailyPrices(string $ticker, array $query = []): array
    {
        $path = sprintf('/tiingo/daily/%s/prices', urlencode($ticker));
        $params = $this->filterParams([
            'startDate' => $query['startDate'] ?? null,
            'endDate' => $query['endDate'] ?? null,
            'resampleFreq' => $query['resampleFreq'] ?? null,
            'format' => $query['format'] ?? null,
        ]);
        return $this->request($path, $params);
    }

    public function fetchDailyMetadata(string $ticker): array
    {
        $path = sprintf('/tiingo/daily/%s', urlencode($ticker));
        return $this->request($path);
    }

    public function fetchCryptoPrices(array $tickers, array $query = []): array
    {
        $symbols = $this->normalizeSymbols($tickers);
        if (empty($symbols)) {
            throw new \RuntimeException('tickers requeridos para crypto', 422);
        }
        $params = $this->filterParams([
            'tickers' => implode(',', $symbols),
            'startDate' => $query['startDate'] ?? null,
            'endDate' => $query['endDate'] ?? null,
            'resampleFreq' => $query['resampleFreq'] ?? null,
        ]);
        return $this->request('/tiingo/crypto/prices', $params);
    }

    public function fetchFxPrices(array $pairs, array $query = []): array
    {
        $symbols = $this->normalizeSymbols($pairs);
        if (empty($symbols)) {
            throw new \RuntimeException('tickers requeridos para FX', 422);
        }
        $params = $this->filterParams([
            'tickers' => implode(',', $symbols),
            'startDate' => $query['startDate'] ?? null,
            'endDate' => $query['endDate'] ?? null,
            'resampleFreq' => $query['resampleFreq'] ?? null,
        ]);
        return $this->request('/tiingo/fx/prices', $params);
    }

    public function search(string $query): array
    {
        return $this->request('/tiingo/utilities/search', ['query' => $query]);
    }

    public function news(array $tickers, array $query = []): array
    {
        $symbols = $this->normalizeSymbols($tickers);
        if (empty($symbols)) {
            throw new \RuntimeException('tickers requeridos para news', 422);
        }
        $params = $this->filterParams([
            'tickers' => implode(',', $symbols),
            'startDate' => $query['startDate'] ?? null,
            'endDate' => $query['endDate'] ?? null,
            'limit' => $query['limit'] ?? null,
            'source' => $query['source'] ?? null,
        ]);
        return $this->request('/tiingo/news', $params);
    }

    private function request(string $path, array $query = []): array
    {
        if ($this->token === '') {
            throw new \RuntimeException('TIINGO_API_TOKEN requerido', 503);
        }

        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $responseHeaders = [];
        $body = $this->httpGet($url, $responseHeaders);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta inválida desde Tiingo', 502);
        }

        // Algunos endpoints devuelven error en campo "error"
        if (isset($decoded['detail']) && is_string($decoded['detail'])) {
            throw new \RuntimeException($decoded['detail'], 502);
        }
        if (isset($decoded['message']) && is_string($decoded['message'])) {
            throw new \RuntimeException($decoded['message'], 502);
        }

        return $decoded;
    }

    private function httpGet(string $url, ?array &$responseHeaders = null): string
    {
        $responseHeaders = [];
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_HTTPHEADER => [
                    'Authorization: Token ' . $this->token,
                    'Accept: application/json',
                ],
                CURLOPT_HEADERFUNCTION => static function ($ch, string $header) use (&$responseHeaders): int {
                    $len = strlen($header);
                    $parts = explode(':', $header, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                    return $len;
                },
            ]);
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);
            if ($body === false) {
                throw new \RuntimeException($curlError !== '' ? $curlError : 'No se pudo conectar a Tiingo', 502);
            }
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Tiingo devolvió HTTP %d', $status), $status);
            }
            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Authorization: Token {$this->token}\r\nAccept: application/json\r\n",
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
            throw new \RuntimeException('No se pudo conectar a Tiingo: ' . $statusLine, $code);
        }
        return (string) $body;
    }

    private function normalizeSymbols(array $symbols): array
    {
        $symbols = array_values(array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), $symbols), static fn ($s) => $s !== ''));
        return array_slice(array_unique($symbols), 0, 50);
    }

    private function filterParams(array $input): array
    {
        return array_filter($input, static fn ($v) => $v !== null && $v !== '');
    }
}
