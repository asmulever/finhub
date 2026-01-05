<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

use FinHub\Infrastructure\Config\Config;

/**
 * Cliente HTTP para extraer acciones argentinas desde RAVA (payload embebido).
 * (Módulo MarketData - Infrastructure)
 */
final class RavaAccionesClient
{
    private string $baseUrl;
    private int $timeoutSeconds;
    private string $userAgent;

    public function __construct(Config $config)
    {
        $this->baseUrl = rtrim((string) $config->get('RAVA_BASE_URL', 'https://www.rava.com'), '/');
        $this->timeoutSeconds = (int) $config->get('RAVA_TIMEOUT_SECONDS', 8);
        $this->userAgent = (string) $config->get(
            'RAVA_USER_AGENT',
            'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120 Safari/537.36'
        );
    }

    /**
     * Devuelve paneles de acciones argentinos agrupados por key (GEN, LID, etc).
     *
     * @return array<string,array<int,array<string,mixed>>>
     */
    public function fetchAccionesRaw(): array
    {
        $url = $this->baseUrl . '/cotizaciones/acciones-argentinas';
        $html = $this->fetchHtml($url);
        $encodedJson = $this->extractDatosAttribute($html);
        $jsonText = html_entity_decode($encodedJson, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $decoded = json_decode($jsonText, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('JSON inválido en respuesta de RAVA (acciones)', 502);
        }
        return $decoded;
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
            throw new \RuntimeException($error !== '' ? $error : 'Error al consultar RAVA (acciones)', 502);
        }
        if ($status >= 400) {
            throw new \RuntimeException(sprintf('RAVA devolvió HTTP %d', $status), $status);
        }

        return (string) $body;
    }

    private function extractDatosAttribute(string $html): string
    {
        $matches = [];
        if (!preg_match('/<acciones-argentinas\\b[^>]*:datos="(?<data>[^"]+)"[^>]*>/i', $html, $matches)) {
            throw new \RuntimeException('No se encontró bloque de datos en RAVA (acciones argentinas)', 502);
        }
        $data = (string) ($matches['data'] ?? '');
        if ($data === '') {
            throw new \RuntimeException('Atributo :datos vacío en RAVA (acciones argentinas)', 502);
        }
        return $data;
    }
}
