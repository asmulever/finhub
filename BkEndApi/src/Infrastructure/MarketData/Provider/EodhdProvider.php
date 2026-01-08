<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData\Provider;

use FinHub\Application\MarketData\Provider\QuoteProviderInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;

/**
 * Adaptador de EODHD con normalización consistente para cotizaciones.
 * Módulo: MarketData (Infrastructure).
 */
final class EodhdProvider implements QuoteProviderInterface
{
    private ?EodhdClient $client;

    public function __construct(?EodhdClient $client)
    {
        $this->client = $client;
    }

    public function name(): string
    {
        return 'eodhd';
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
        $symbolWithExchange = $this->formatSymbol($requested, $exchange);
        $useUsQuote = str_ends_with($symbolWithExchange, '.US') || str_ends_with($symbolWithExchange, '.BA');

        $raw = $useUsQuote
            ? $client->fetchUsQuoteDelayed($symbolWithExchange)
            : $client->fetchEod($symbolWithExchange);

        if (isset($raw[0]) && is_array($raw[0])) {
            $raw = $raw[0];
        }

        return $this->normalizeQuote($raw, $requested);
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

        $batchSymbols = [];
        foreach ($requested as $original => $upper) {
            $batchSymbols[$original] = $this->formatSymbol($upper, $exchange);
        }

        $raw = $client->fetchRealTimeBatch(array_values($batchSymbols));
        $indexed = $this->indexBatch($raw);
        $normalized = [];
        foreach ($batchSymbols as $original => $withExchange) {
            $lookup = strtoupper($withExchange);
            $row = $indexed[$lookup] ?? null;
            if ($row === null) {
                continue;
            }
            $normalized[$original] = $this->normalizeQuote($row, $original);
        }

        return $normalized;
    }

    private function normalizeQuote(array $quote, string $requestedSymbol): array
    {
        $close = $quote['close'] ?? $quote['price'] ?? $quote['last'] ?? null;
        $asOf = $quote['timestamp'] ?? $quote['last_update'] ?? $quote['datetime'] ?? null;
        return [
            'symbol' => $requestedSymbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'open' => $this->floatOrNull($quote['open'] ?? null),
            'high' => $this->floatOrNull($quote['high'] ?? null),
            'low' => $this->floatOrNull($quote['low'] ?? null),
            'close' => $this->floatOrNull($close),
            'previous_close' => $this->floatOrNull($quote['previousClose'] ?? $quote['previous_close'] ?? null),
            'asOf' => is_string($asOf) ? $asOf : null,
            'provider' => $this->name(),
            'payload' => $quote,
        ];
    }

    private function indexBatch(array $data): array
    {
        $indexed = [];
        $rows = $data;
        if (isset($data['code']) || isset($data['symbol'])) {
            $rows = [$data];
        }
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $code = strtoupper((string) ($row['code'] ?? $row['symbol'] ?? ''));
            if ($code === '') {
                continue;
            }
            $indexed[$code] = $row;
        }
        return $indexed;
    }

    private function formatSymbol(string $symbol, ?string $exchange): string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return '';
        }
        if (str_contains($symbol, '.')) {
            return $symbol;
        }
        $exchangeUpper = $exchange !== null ? strtoupper(trim($exchange)) : 'US';
        return sprintf('%s.%s', $symbol, $exchangeUpper);
    }

    private function client(): EodhdClient
    {
        if ($this->client === null) {
            throw new \RuntimeException('Servicio EODHD no configurado', 503);
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
