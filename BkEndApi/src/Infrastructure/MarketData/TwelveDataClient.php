<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

final class TwelveDataClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeoutSeconds;

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
    public function listStocks(): array
    {
        $params = [
            'apikey' => $this->apiKey,
            'mic_code' => 'XBUE',
        ];
        $response = $this->request('stocks', $params);
        $data = $response['data'] ?? $response;
        if (!is_array($data)) {
            throw new \RuntimeException('Respuesta inválida desde Twelve Data (stocks)', 502);
        }
        return $data;
    }

    /**
     * Ejecuta la llamada HTTP y devuelve el payload JSON decodificado.
     */
    private function request(string $endpoint, array $query): array
    {
        $url = sprintf('%s/%s?%s', $this->baseUrl, ltrim($endpoint, '/'), http_build_query($query, '', '&', PHP_QUERY_RFC3986));
        $rawBody = $this->httpGet($url);
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

    /**
     * Realiza un GET usando cURL si está disponible, o stream_context como alternativa.
     */
    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeoutSeconds);
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
