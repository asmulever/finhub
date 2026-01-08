<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente sencillo para Stooq (sin API key).
 */
final class StooqClient
{
    private string $baseUrl;
    private int $timeoutSeconds;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim((string) $config->get('STOOQ_BASE_URL', 'https://stooq.pl'), '/');
        $this->timeoutSeconds = max(3, (int) $config->get('STOOQ_TIMEOUT_SECONDS', 5));
    }

    /**
     * Consulta cotizaciones actuales para uno o más símbolos (sufijo de mercado, ej: .us).
     *
     * @param array<int,string> $symbols
     */
    public function fetchQuotes(array $symbols): array
    {
        $tickers = $this->normalizeSymbols($symbols);
        if (empty($tickers)) {
            throw new \RuntimeException('symbols requeridos para Stooq', 422);
        }
        $params = [
            's' => implode(',', $tickers),
            'f' => 'sd2t2ohlcv',
            'h' => '1',
            'e' => 'json',
        ];
        $url = sprintf('%s/q/l/?%s', $this->baseUrl, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $raw = $this->httpGet($url);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['symbols'])) {
            throw new \RuntimeException('Respuesta inválida desde Stooq (quotes)', 502);
        }
        return $decoded;
    }

    /**
     * Serie histórica (EOD) en CSV convertida a array asociativo.
     */
    public function fetchHistory(string $symbolWithMarket, string $interval = 'd'): array
    {
        $interval = in_array($interval, ['d', 'w', 'm'], true) ? $interval : 'd';
        $params = [
            's' => strtolower(trim($symbolWithMarket)),
            'i' => $interval,
        ];
        $url = sprintf('%s/q/d/l/?%s', $this->baseUrl, http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $csv = $this->httpGet($url);
        return $this->parseCsv($csv);
    }

    /**
     * Listado manual de sufijos de mercado soportados por Stooq.
     *
     * @return array<int,string>
     */
    public function availableMarkets(): array
    {
        return [
            'us', // Estados Unidos
            'pl', // Polonia
            'uk', // Reino Unido
            'de', // Alemania
            'fr', // Francia
            'es', // España
            'it', // Italia
            'jp', // Japón
            'hk', // Hong Kong
            'br', // Brasil
            'ca', // Canadá
            'mx', // México
            'au', // Australia
            'nl', // Países Bajos
            'se', // Suecia
            'ch', // Suiza
            'pt', // Portugal
        ];
    }

    private function httpGet(string $url): string
    {
        if (function_exists('curl_init')) {
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
                throw new \RuntimeException($error !== '' ? $error : 'No se pudo conectar a Stooq', 502);
            }
            if ($status >= 400) {
                throw new \RuntimeException(sprintf('Stooq devolvió HTTP %d', $status), $status);
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
            throw new \RuntimeException('No se pudo conectar a Stooq: ' . $statusLine, $code);
        }
        return (string) $body;
    }

    private function parseCsv(string $csv): array
    {
        $lines = preg_split('/\\r?\\n/', trim($csv));
        if ($lines === false || count($lines) < 2) {
            return [];
        }
        $header = str_getcsv(array_shift($lines) ?: '');
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $values = str_getcsv($line);
            if ($header !== false && is_array($values)) {
                $rows[] = array_combine($header, $values);
            }
        }
        return $rows;
    }

    private function normalizeSymbols(array $symbols): array
    {
        $symbols = array_values(array_filter(array_map(static fn ($s) => strtolower(trim((string) $s)), $symbols), static fn ($s) => $s !== ''));
        return array_slice(array_unique($symbols), 0, 50);
    }
}
