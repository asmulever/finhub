<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\Dto\StockItem;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\MarketData\ProviderMetrics;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

final class PriceService
{
    private ?TwelveDataClient $twelveClient;
    private ?EodhdClient $eodhdClient;
    private ProviderMetrics $metrics;

    public function __construct(?TwelveDataClient $client, ?EodhdClient $eodhdClient, ProviderMetrics $metrics)
    {
        $this->twelveClient = $client;
        $this->eodhdClient = $eodhdClient;
        $this->metrics = $metrics;
    }

    /**
     * Devuelve el quote de precio normalizado para un símbolo.
     */
    public function getPrice(PriceRequest $request): array
    {
        $snapshot = $this->fetchSnapshot($request->getSymbol());
        $close = $this->floatOrNull($snapshot['close']);
        if ($close === null) {
            throw new \RuntimeException('Precio no disponible para el símbolo solicitado', 502);
        }

        return [
            'symbol' => $snapshot['symbol'],
            'name' => $snapshot['name'] ?? null,
            'currency' => $snapshot['currency'] ?? null,
            'close' => $close,
            'open' => $this->floatOrNull($snapshot['open'] ?? null),
            'high' => $this->floatOrNull($snapshot['high'] ?? null),
            'low' => $this->floatOrNull($snapshot['low'] ?? null),
            'previous_close' => $this->floatOrNull($snapshot['previous_close'] ?? null),
            'asOf' => $snapshot['as_of'] ?? null,
            'source' => $snapshot['source'] ?? null,
        ];
    }

    /**
     * Devuelve la lista de tickers disponibles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStocks(string $exchange = 'US'): array
    {
        if ($this->eodhdClient === null) {
            throw new \RuntimeException('Servicio de EODHD no configurado (falta API key)', 503);
        }
        $raw = $this->eodhdClient->fetchExchangeSymbols($exchange);
        $items = [];
        foreach ($raw as $row) {
            $symbol = $row['Code'] ?? $row['symbol'] ?? null;
            if (!$symbol) {
                continue;
            }
            $item = StockItem::fromArray([
                'symbol' => $symbol,
                'name' => $row['Name'] ?? $row['name'] ?? null,
                'currency' => $row['Currency'] ?? $row['currency'] ?? null,
                'exchange' => $row['Exchange'] ?? $exchange,
                'country' => $row['Country'] ?? null,
                'mic_code' => $row['OperatingMIC'] ?? $row['mic_code'] ?? null,
                'type' => $row['Type'] ?? $row['type'] ?? null,
            ]);
            $items[] = $item->toArray();
        }
        return $items;
    }

    /**
     * Devuelve snapshot crudo con el proveedor usado (para Data Lake).
     */
    public function fetchSnapshot(string $symbol): array
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        // 1) Twelve Data
        if ($this->twelveClient !== null) {
            try {
                $quote = $this->twelveClient->fetchQuote($symbol);
                $this->metrics->record('twelvedata', true);
                return $this->normalizeTwelveDataQuote($quote, $symbol);
            } catch (\Throwable $e) {
                $this->metrics->record('twelvedata', false);
            }
        }

        // 2) Fallback EODHD
        if ($this->eodhdClient !== null) {
            try {
                $quote = $this->eodhdClient->fetchLive($symbol);
                $this->metrics->record('eodhd', true);
                return $this->normalizeEodhdQuote($quote, $symbol);
            } catch (\Throwable $e) {
                $this->metrics->record('eodhd', false);
                // intentar EOD como último recurso
                try {
                    $eod = $this->eodhdClient->fetchEod($symbol);
                    $this->metrics->record('eodhd', true);
                    return $this->normalizeEodhdEod($eod, $symbol);
                } catch (\Throwable $e2) {
                    $this->metrics->record('eodhd', false);
                }
            }
        }

        throw new \RuntimeException('No se pudo obtener el precio desde los proveedores configurados', 502);
    }

    private function normalizeTwelveDataQuote(array $quote, string $symbol): array
    {
        return [
            'symbol' => $quote['symbol'] ?? $symbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'close' => $quote['close'] ?? $quote['price'] ?? null,
            'open' => $quote['open'] ?? null,
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'previous_close' => $quote['previous_close'] ?? null,
            'as_of' => $quote['datetime'] ?? ($quote['timestamp'] ?? null),
            'source' => 'twelvedata',
            'payload' => $quote,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function normalizeEodhdQuote(array $quote, string $symbol): array
    {
        $close = $quote['close'] ?? $quote['price'] ?? $quote['last'] ?? null;
        $asOf = $quote['timestamp'] ?? $quote['last_update'] ?? $quote['datetime'] ?? null;
        return [
            'symbol' => $quote['code'] ?? $quote['symbol'] ?? $symbol,
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'close' => $close,
            'open' => $quote['open'] ?? null,
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'previous_close' => $quote['previousClose'] ?? $quote['previous_close'] ?? null,
            'as_of' => $asOf,
            'source' => 'eodhd',
            'payload' => $quote,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function normalizeEodhdEod(array $eod, string $symbol): array
    {
        if (isset($eod[0]) && is_array($eod[0])) {
            $latest = $eod[0];
        } elseif (is_array($eod)) {
            $latest = $eod;
        } else {
            throw new \RuntimeException('Respuesta inválida de EODHD (EOD)', 502);
        }
        return [
            'symbol' => $latest['code'] ?? $symbol,
            'name' => $latest['name'] ?? null,
            'currency' => $latest['currency'] ?? null,
            'close' => $latest['close'] ?? null,
            'open' => $latest['open'] ?? null,
            'high' => $latest['high'] ?? null,
            'low' => $latest['low'] ?? null,
            'previous_close' => $latest['previousClose'] ?? $latest['previous_close'] ?? null,
            'as_of' => $latest['date'] ?? $latest['datetime'] ?? null,
            'source' => 'eodhd',
            'payload' => $eod,
            'http_status' => 200,
            'error_code' => null,
            'error_msg' => null,
        ];
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}
