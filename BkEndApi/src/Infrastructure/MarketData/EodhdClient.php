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

    public function __construct(Config $config)
    {
        $this->apiKey = (string) $config->get('eodhd_api_key', '');
        $this->baseUrl = rtrim((string) $config->get('EODHD_BASE_URL', 'https://eodhd.com'), '/');
        $this->timeout = (int) $config->get('EODHD_TIMEOUT_SECONDS', 5);
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
     * Devuelve la lista de símbolos de un exchange (ej: US, XBUE).
     *
     * @throws \RuntimeException si falta API key o hay error HTTP.
     */
    public function fetchExchangeSymbols(string $exchange): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('EODHD API key requerida');
        }
        $url = sprintf('%s/api/exchange-symbol-list/%s?api_token=%s&fmt=json', $this->baseUrl, urlencode($exchange), urlencode($this->apiKey));
        return $this->doRequest($url);
    }

    /**
     * Ejecuta la llamada HTTP con cURL y retorna el JSON decodificado.
     */
    private function doRequest(string $url): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

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
}
