<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData\Provider;

use FinHub\Application\MarketData\Provider\FxRateProviderInterface;
use FinHub\Application\MarketData\Provider\QuoteProviderInterface;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

/**
 * Adaptador de Twelve Data que normaliza cotizaciones y FX.
 * Módulo: MarketData (Infrastructure).
 */
final class TwelveDataProvider implements QuoteProviderInterface, FxRateProviderInterface
{
    private ?TwelveDataClient $client;

    public function __construct(?TwelveDataClient $client)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'twelvedata';
    }

    public function isConfigured(): bool
    {
        return $this->client !== null;
    }

    public function quote(string $symbol, ?string $exchange = null): array
    {
        $client = $this->client();
        $requested = strtoupper(trim($symbol));
        if ($requested === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }
        $querySymbol = str_contains($requested, '.') ? explode('.', $requested, 2)[0] : $requested;
        $raw = $client->fetchQuote($querySymbol);
        return $this->normalizeQuote($raw, $querySymbol, $requested);
    }

    public function quotes(array $symbols, ?string $exchange = null): array
    {
        $client = $this->client();
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

        $querySymbols = array_unique(array_map(
            static fn (string $sym): string => str_contains($sym, '.') ? explode('.', $sym, 2)[0] : $sym,
            array_values($requested)
        ));

        $raw = $client->fetchQuotes($querySymbols);
        $normalized = [];
        foreach ($requested as $original => $upper) {
            $base = str_contains($upper, '.') ? explode('.', $upper, 2)[0] : $upper;
            $match = $raw[$upper] ?? $raw[$original] ?? $raw[$base] ?? null;
            if ($match === null) {
                continue;
            }
            $normalized[$original] = $this->normalizeQuote($match, $base, $original);
        }

        return $normalized;
    }

    public function rate(string $pair): array
    {
        $client = $this->client();
        $raw = $client->fetchExchangeRate($pair);
        $rate = $this->floatOrNull($raw['rate'] ?? $raw['value'] ?? $raw['price'] ?? null);
        $at = $raw['timestamp'] ?? $raw['datetime'] ?? null;
        return [
            'rate' => $rate,
            'at' => is_string($at) ? $at : null,
            'source' => $this->name(),
        ];
    }

    private function normalizeQuote(array $quote, string $symbolForQuery, string $requestedSymbol): array
    {
        $asOf = $quote['datetime'] ?? ($quote['timestamp'] ?? null);
        return [
            'symbol' => $requestedSymbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'open' => $this->floatOrNull($quote['open'] ?? null),
            'high' => $this->floatOrNull($quote['high'] ?? null),
            'low' => $this->floatOrNull($quote['low'] ?? null),
            'close' => $this->floatOrNull($quote['close'] ?? $quote['price'] ?? null),
            'previous_close' => $this->floatOrNull($quote['previous_close'] ?? null),
            'asOf' => is_string($asOf) ? $asOf : null,
            'provider' => $this->name(),
            'payload' => $quote,
        ];
    }

    private function client(): TwelveDataClient
    {
        if ($this->client === null) {
            throw new \RuntimeException('Servicio Twelve Data no configurado', 503);
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
