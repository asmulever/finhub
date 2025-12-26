<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente mínimo para EODHD (solo endpoints free: EOD diario y lista de símbolos).
 * No reutiliza clases existentes de otros flujos.
 */
final class EodhdClient
{
    private string $apiKey;
    private string $baseUrl;
    private int $timeout;
    private string $cacheDir;
    private ?array $lastRateLimit = null;

    public function __construct(Config $config)
    {
        $this->apiKey = (string) $config->get('eodhd_api_key', '');
        $this->baseUrl = rtrim((string) $config->get('EODHD_BASE_URL', 'https://eodhd.com'), '/');
        $this->timeout = (int) $config->get('EODHD_TIMEOUT_SECONDS', 5);
        $this->cacheDir = rtrim((string) $config->get('EODHD_CACHE_DIR', $config->get('CACHE_DIR', sys_get_temp_dir() . '/finhub_cache')), '/');
    }

    /**
     * Devuelve el EOD diario de un símbolo (ej: AAPL.US).
     *
     * @throws \RuntimeException si falta API key o hay error HTTP.
     */
    public function fetchEod(string $symbol): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $url = sprintf('%s/api/eod/%s?api_token=%s&fmt=json', $this->baseUrl, urlencode($symbol), urlencode($this->apiKey));
        return $this->doRequest($url);
    }

    /**
     * Precio en vivo de un símbolo.
     */
    public function fetchLive(string $symbol): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $url = sprintf('%s/api/live/%s?api_token=%s&fmt=json', $this->baseUrl, urlencode($symbol), urlencode($this->apiKey));
        return $this->doRequest($url);
    }

    /**
     * Devuelve la lista de símbolos de un exchange (ej: US, XBUE).
     *
     * @throws \RuntimeException si falta API key o hay error HTTP.
     */
    public function fetchExchangeSymbols(string $exchange): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $cacheKey = sprintf('eodhd_exchange_%s.json', strtoupper($exchange));
        $cached = $this->readCache($cacheKey);
        if ($cached !== null) {
            return $cached;
        }
        $url = sprintf('%s/api/exchange-symbol-list/%s?api_token=%s&fmt=json', $this->baseUrl, urlencode($exchange), urlencode($this->apiKey));
        $data = $this->doRequest($url);
        $this->writeCache($cacheKey, $data, 3600); // 1h de cache
        return $data;
    }

    /**
     * Lista de exchanges disponibles (para armar combos).
     *
     * @throws \RuntimeException si falta API key o hay error HTTP.
     */
    public function fetchExchangesList(): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $url = sprintf('%s/api/exchanges-list/?api_token=%s&fmt=json', $this->baseUrl, urlencode($this->apiKey));
        return $this->doRequest($url);
    }

    /**
     * Información de cuenta (plan y límites restantes).
     */
    public function fetchUser(): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $url = sprintf('%s/api/user/?api_token=%s&fmt=json', $this->baseUrl, urlencode($this->apiKey));
        return $this->doRequest($url);
    }

    public function getLastRateLimit(): ?array
    {
        return $this->lastRateLimit;
    }

    /**
     * Ejecuta la llamada HTTP con cURL y retorna el JSON decodificado.
     */
    private function doRequest(string $url): array
    {
        $this->lastRateLimit = null;
        $responseHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
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
        $this->lastRateLimit = $this->extractRateLimit($responseHeaders);

        if ($body === false) {
            throw new \RuntimeException($curlError !== '' ? $curlError : 'Error al consultar EODHD');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException(sprintf('Respuesta inválida de EODHD (HTTP %d)', $status));
        }
        if ($status < 200 || $status >= 300) {
            $message = $decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $status);
            throw new \RuntimeException((string) $message, $status);
        }
        return $decoded;
    }

    private function extractRateLimit(array $headers): ?array
    {
        $limit = null;
        $remaining = null;
        $reset = null;
        if (isset($headers['x-ratelimit-limit']) && is_numeric($headers['x-ratelimit-limit'])) {
            $limit = (int) $headers['x-ratelimit-limit'];
        }
        if (isset($headers['x-ratelimit-remaining']) && is_numeric($headers['x-ratelimit-remaining'])) {
            $remaining = (int) $headers['x-ratelimit-remaining'];
        }
        if (isset($headers['x-ratelimit-reset']) && is_numeric($headers['x-ratelimit-reset'])) {
            $reset = (int) $headers['x-ratelimit-reset'];
        }
        if ($limit === null && $remaining === null && $reset === null) {
            return null;
        }
        return [
            'limit' => $limit,
            'remaining' => $remaining,
            'reset' => $reset,
        ];
    }

    private function readCache(string $file): ?array
    {
        $this->ensureCacheDir();
        $path = $this->cacheDir . '/' . $file;
        if (!file_exists($path)) {
            return null;
        }
        $contents = file_get_contents($path);
        $decoded = json_decode((string) $contents, true);
        if (!is_array($decoded)) {
            return null;
        }
        $expiresAt = $decoded['_expires_at'] ?? null;
        if ($expiresAt !== null && time() > (int) $expiresAt) {
            @unlink($path);
            return null;
        }
        return $decoded['data'] ?? $decoded;
    }

    private function writeCache(string $file, array $data, int $ttlSeconds): void
    {
        $this->ensureCacheDir();
        $payload = [
            '_expires_at' => time() + $ttlSeconds,
            'data' => $data,
        ];
        file_put_contents($this->cacheDir . '/' . $file, json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function ensureCacheDir(): void
    {
        if (!is_dir($this->cacheDir)) {
            mkdir($this->cacheDir, 0775, true);
        }
    }
}
