<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente mínimo para Polygon (cobertura de endpoints clave para demo).
 */
final class PolygonClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(Config $config)
    {
        $this->apiKey = trim((string) $config->get('POLYGON_API_KEY', ''));
        $this->baseUrl = rtrim((string) $config->get('POLYGON_BASE_URL', 'https://api.polygon.io'), '/');
        $this->timeoutSeconds = max(3, (int) $config->get('POLYGON_TIMEOUT_SECONDS', 5));
    }

    public function listTickers(array $query = []): array
    {
        $params = [
            'ticker' => $query['ticker'] ?? ($query['search'] ?? null),
            'search' => $query['search'] ?? null,
            'active' => $query['active'] ?? null,
            'market' => $query['market'] ?? null,
            'type' => $query['type'] ?? null,
            'locale' => $query['locale'] ?? 'us',
            'limit' => $query['limit'] ?? 20,
            'order' => $query['order'] ?? 'asc',
            'sort' => $query['sort'] ?? 'ticker',
        ];
        return $this->request('/v3/reference/tickers', $params);
    }

    public function tickerDetails(string $symbol): array
    {
        return $this->request(sprintf('/v3/reference/tickers/%s', urlencode($symbol)));
    }

    public function aggregates(string $symbol, int $multiplier, string $timespan, string $from, string $to, bool $adjusted = true, string $sort = 'desc', int $limit = 120): array
    {
        $path = sprintf(
            '/v2/aggs/ticker/%s/range/%d/%s/%s/%s',
            urlencode($symbol),
            $multiplier,
            urlencode($timespan),
            urlencode($from),
            urlencode($to)
        );
        $params = [
            'adjusted' => $adjusted ? 'true' : 'false',
            'sort' => $sort,
            'limit' => $limit,
        ];
        return $this->request($path, $params);
    }

    public function previousClose(string $symbol, bool $adjusted = true): array
    {
        $path = sprintf('/v2/aggs/ticker/%s/prev', urlencode($symbol));
        return $this->request($path, ['adjusted' => $adjusted ? 'true' : 'false']);
    }

    public function dailyOpenClose(string $symbol, string $date, bool $adjusted = true): array
    {
        $path = sprintf('/v1/open-close/%s/%s', urlencode($symbol), urlencode($date));
        return $this->request($path, ['adjusted' => $adjusted ? 'true' : 'false']);
    }

    public function groupedDaily(string $date, string $market = 'stocks', string $locale = 'us', bool $adjusted = true): array
    {
        $path = sprintf('/v2/aggs/grouped/locale/%s/market/%s/%s', urlencode($locale), urlencode($market), urlencode($date));
        return $this->request($path, ['adjusted' => $adjusted ? 'true' : 'false']);
    }

    public function lastTrade(string $symbol): array
    {
        $path = sprintf('/v2/last/trade/%s', urlencode($symbol));
        return $this->request($path);
    }

    public function lastQuote(string $symbol): array
    {
        $path = sprintf('/v2/last/nbbo/%s', urlencode($symbol));
        return $this->request($path);
    }

    public function snapshotTicker(string $symbol, string $market = 'stocks', string $locale = 'us'): array
    {
        $path = sprintf('/v2/snapshot/locale/%s/markets/%s/tickers/%s', urlencode($locale), urlencode($market), urlencode($symbol));
        return $this->request($path);
    }

    public function tickerNews(string $symbol, int $limit = 10): array
    {
        $params = [
            'ticker' => $symbol,
            'limit' => $limit,
            'order' => 'desc',
            'sort' => 'published_utc',
        ];
        return $this->request('/v2/reference/news', $params);
    }

    public function dividends(string $symbol, int $limit = 50): array
    {
        $params = [
            'ticker' => $symbol,
            'limit' => $limit,
            'order' => 'desc',
        ];
        return $this->request('/v3/reference/dividends', $params);
    }

    public function splits(string $symbol, int $limit = 50): array
    {
        $params = [
            'ticker' => $symbol,
            'limit' => $limit,
            'order' => 'desc',
        ];
        return $this->request('/v3/reference/splits', $params);
    }

    public function exchanges(string $assetClass = 'stocks', ?string $locale = null): array
    {
        $params = [
            'asset_class' => $assetClass,
        ];
        if ($locale !== null && $locale !== '') {
            $params['locale'] = $locale;
        }
        return $this->request('/v3/reference/exchanges', $params);
    }

    public function marketStatus(): array
    {
        return $this->request('/v1/marketstatus/now');
    }

    private function request(string $path, array $query = []): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('POLYGON_API_KEY requerida', 503);
        }
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        $query['apiKey'] = $this->apiKey;
        $url = $this->baseUrl . $path;
        if (!empty($query)) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $responseHeaders = [];
        $body = $this->httpGet($url, $responseHeaders);
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Respuesta inválida desde Polygon', 502);
        }
        if (($decoded['error'] ?? '') !== '') {
            $code = (int) ($decoded['status'] ?? 502);
            if ($code < 100 || $code >= 600) {
                $code = 502;
            }
            throw new \RuntimeException((string) $decoded['error'], $code);
        }
        if (isset($decoded['status']) && $decoded['status'] === 'ERROR') {
            $message = (string) ($decoded['message'] ?? 'Error en Polygon');
            $code = (int) ($decoded['status_code'] ?? 502);
            if ($code < 100 || $code >= 600) {
                $code = 502;
            }
            throw new \RuntimeException($message, $code);
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
                throw new \RuntimeException($curlError !== '' ? $curlError : 'No se pudo conectar a Polygon', 502);
            }
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Polygon devolvió HTTP %d', $status), $status);
            }
            return (string) $body;
        }

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeoutSeconds,
                'header' => "Accept: application/json\r\n",
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
            throw new \RuntimeException('No se pudo conectar a Polygon: ' . $statusLine, $code);
        }
        return (string) $body;
    }
}
