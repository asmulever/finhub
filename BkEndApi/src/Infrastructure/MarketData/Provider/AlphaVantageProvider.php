<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData\Provider;

use FinHub\Application\MarketData\Provider\QuoteProviderInterface;
use FinHub\Infrastructure\MarketData\AlphaVantageClient;

/**
 * Adaptador de Alpha Vantage (GLOBAL_QUOTE) con normalización homogénea.
 * Módulo: MarketData (Infrastructure).
 */
final class AlphaVantageProvider implements QuoteProviderInterface
{
    private ?AlphaVantageClient $client;

    public function __construct(?AlphaVantageClient $client)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'alphavantage';
    }

    public function isConfigured(): bool
    {
        return $this->client !== null && $this->client->hasApiKey();
    }

    public function quote(string $symbol, ?string $exchange = null): array
    {
        $client = $this->client();
        $requested = strtoupper(trim($symbol));
        if ($requested === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $raw = $client->fetchGlobalQuote($requested);
        $payload = $raw['Global Quote'] ?? $raw['globalQuote'] ?? $raw;
        if (!is_array($payload)) {
            throw new \RuntimeException('Respuesta inválida de Alpha Vantage', 502);
        }

        $map = [
            '01. symbol' => 'symbol',
            '02. open' => 'open',
            '03. high' => 'high',
            '04. low' => 'low',
            '05. price' => 'price',
            '07. latest trading day' => 'date',
            '08. previous close' => 'previous_close',
        ];
        $norm = [];
        foreach ($map as $k => $dest) {
            if (isset($payload[$k])) {
                $norm[$dest] = $payload[$k];
            }
        }

        return [
            'symbol' => $requested,
            'name' => $requested,
            'currency' => null,
            'open' => $this->floatOrNull($norm['open'] ?? null),
            'high' => $this->floatOrNull($norm['high'] ?? null),
            'low' => $this->floatOrNull($norm['low'] ?? null),
            'close' => $this->floatOrNull($norm['price'] ?? null),
            'previous_close' => $this->floatOrNull($norm['previous_close'] ?? null),
            'asOf' => $payload['date'] ?? $payload['latestTradingDay'] ?? null,
            'provider' => $this->name(),
            'payload' => $payload,
        ];
    }

    public function quotes(array $symbols, ?string $exchange = null): array
    {
        $requested = [];
        foreach ($symbols as $symbol) {
            $s = strtoupper(trim((string) $symbol));
            if ($s === '' || isset($requested[$s])) {
                continue;
            }
            $requested[$s] = $s;
        }
        if (empty($requested)) {
            throw new \RuntimeException('Símbolos requeridos', 422);
        }

        $results = [];
        $errors = [];
        foreach ($requested as $symbol) {
            try {
                $results[$symbol] = $this->quote($symbol, $exchange);
            } catch (\Throwable $e) {
                $errors[] = $e->getMessage();
            }
        }

        if (empty($results) && !empty($errors)) {
            throw new \RuntimeException($errors[0], 502);
        }

        return $results;
    }

    private function client(): AlphaVantageClient
    {
        if ($this->client === null || !$this->client->hasApiKey()) {
            throw new \RuntimeException('Servicio Alpha Vantage no configurado', 503);
        }
        return $this->client;
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }
}
