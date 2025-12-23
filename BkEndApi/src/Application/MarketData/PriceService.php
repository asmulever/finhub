<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\Dto\StockItem;
use FinHub\Infrastructure\MarketData\TwelveDataClient;

final class PriceService
{
    private ?TwelveDataClient $client;

    public function __construct(?TwelveDataClient $client)
    {
        $this->client = $client;
    }

    /**
     * Devuelve el quote de precio normalizado para un símbolo.
     */
    public function getPrice(PriceRequest $request): array
    {
        if ($this->client === null) {
            throw new \RuntimeException('Servicio de precios no configurado (falta TWELVE_DATA_API_KEY)', 503);
        }

        $quote = $this->client->fetchQuote($request->getSymbol());

        $close = $this->floatOrNull($quote['close'] ?? $quote['price'] ?? null);
        if ($close === null) {
            throw new \RuntimeException('Precio no disponible para el símbolo solicitado', 502);
        }

        return [
            'symbol' => $quote['symbol'] ?? $request->getSymbol(),
            'name' => $quote['name'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'close' => $close,
            'open' => $this->floatOrNull($quote['open'] ?? null),
            'high' => $this->floatOrNull($quote['high'] ?? null),
            'low' => $this->floatOrNull($quote['low'] ?? null),
            'previous_close' => $this->floatOrNull($quote['previous_close'] ?? null),
            'asOf' => $quote['datetime'] ?? ($quote['timestamp'] ?? null),
            'source' => 'twelvedata',
        ];
    }

    /**
     * Devuelve la lista de tickers disponibles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listStocks(): array
    {
        if ($this->client === null) {
            throw new \RuntimeException('Servicio de precios no configurado (falta TWELVE_DATA_API_KEY)', 503);
        }
        $raw = $this->client->listStocks();
        $items = [];
        foreach ($raw as $row) {
            if (!isset($row['symbol'])) {
                continue;
            }
            $item = StockItem::fromArray($row);
            $items[] = $item->toArray();
        }
        return $items;
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (float) $value;
    }
}
