<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente HTTP para consultar histórico diario de una especie en RAVA.
 * (Módulo MarketData - Infrastructure)
 */
final class RavaHistoricosClient
{
    private string $baseUrl;
    private string $historicosBaseUrl;
    private int $timeoutSeconds;
    private string $userAgent;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim((string) $config->get('RAVA_BASE_URL', 'https://www.rava.com'), '/');
        $this->historicosBaseUrl = rtrim((string) $config->get('RAVA_HISTORICOS_BASE_URL', 'https://clasico.rava.com'), '/');
        $this->timeoutSeconds = (int) $config->get('RAVA_TIMEOUT_SECONDS', 8);
        $this->userAgent = (string) $config->get(
            'RAVA_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36'
        );
    }

    /**
     * Devuelve el histórico de una especie. Reintenta 1 vez ante token inválido.
     *
     * @return array{body?:array<int,array<string,mixed>>,error?:string}
     */
    public function fetchHistoricos(string $especie, ?string $fechaInicio = null, ?string $fechaFin = null): array
    {
        $symbol = trim($especie);
        if ($symbol === '') {
            throw new \InvalidArgumentException('especie requerida');
        }
        $from = $fechaInicio !== null && trim($fechaInicio) !== '' ? trim($fechaInicio) : '0000-00-00';
        $to = $fechaFin !== null && trim($fechaFin) !== '' ? trim($fechaFin) : (new \DateTimeImmutable('today'))->format('Y-m-d');

        $token = $this->fetchAccessToken($symbol);
        try {
            return $this->performHistoricosRequest($symbol, $from, $to, $token);
        } catch (\RuntimeException $e) {
            if (str_contains(strtolower($e->getMessage()), 'token')) {
                $freshToken = $this->fetchAccessToken($symbol);
                if ($freshToken !== '' && $freshToken !== $token) {
                    return $this->performHistoricosRequest($symbol, $from, $to, $freshToken);
                }
            }
            throw $e;
        }
    }

    private function performHistoricosRequest(string $especie, string $fechaInicio, string $fechaFin, string $token): array
    {
        $url = $this->historicosBaseUrl . '/lib/restapi/v3/publico/cotizaciones/historicos';
        $payload = http_build_query([
            'access_token' => $token,
            'especie' => $especie,
            'fecha_inicio' => $fechaInicio,
            'fecha_fin' => $fechaFin,
        ], '', '&');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json, text/plain, */*',
                'Accept-Language: es-AR,es;q=0.9,en;q=0.8',
                'Origin: https://www.rava.com',
                'Referer: https://www.rava.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Error al consultar histórico RAVA', 502);
        }
        if ($status >= 500) {
            throw new \RuntimeException(sprintf('RAVA histórico devolvió HTTP %d', $status), $status);
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON inválido en histórico RAVA', 502);
        }
        if (isset($decoded['error'])) {
            $err = is_array($decoded['error']) ? json_encode($decoded['error']) : (string) $decoded['error'];
            throw new \RuntimeException($err !== '' ? $err : 'Error de token en histórico RAVA', $status === 0 ? 401 : $status);
        }
        if (!isset($decoded['body']) || !is_array($decoded['body'])) {
            throw new \RuntimeException('Estructura inesperada en histórico RAVA', 502);
        }

        return $decoded;
    }

    private function fetchAccessToken(string $especie): string
    {
        $url = $this->baseUrl . '/perfil/' . rawurlencode($especie);
        $html = $this->fetchHtml($url);
        $token = $this->extractAccessToken($html);
        if ($token === null) {
            throw new \RuntimeException('No se pudo extraer access_token de RAVA', 502);
        }
        return $token;
    }

    private function fetchHtml(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_ENCODING => '',
            CURLOPT_USERAGENT => $this->userAgent,
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
                'Accept-Language: es-AR,es;q=0.9,en;q=0.8',
                'Referer: https://www.rava.com/',
            ],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new \RuntimeException($error !== '' ? $error : 'Error al consultar perfil RAVA', 502);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('RAVA perfil devolvió HTTP %d', $status), $status);
        }

        return (string) $body;
    }

    private function extractAccessToken(string $html): ?string
    {
        $matches = [];
        if (preg_match('/<navbar-c\\b[^>]*:access_token="(?<token>[^"]+)"/i', $html, $matches)) {
            $token = trim((string) ($matches['token'] ?? ''), " \t\n\r\0\x0B'\"");
            return $token === '' ? null : $token;
        }
        if (preg_match('/access_token\\s*[:=]\\s*"(?<token>[^"]+)"/i', $html, $matches)) {
            $token = trim((string) ($matches['token'] ?? ''), " \t\n\r\0\x0B'\"");
            return $token === '' ? null : $token;
        }
        return null;
    }
}
