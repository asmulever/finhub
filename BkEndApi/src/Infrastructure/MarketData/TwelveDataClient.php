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
