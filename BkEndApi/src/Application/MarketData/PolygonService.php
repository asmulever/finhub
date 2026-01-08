<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\MarketData\PolygonClient;

/**
 * Orquesta llamadas de demo a Polygon (Application layer).
 */
final class PolygonService
{
    private ?PolygonClient $client;

    public function __construct(?PolygonClient $client)
    {
        $this->client = $client;
    }

    public function listTickers(array $query): array
    {
        return $this->client()->listTickers($query);
    }

    public function tickerDetails(string $symbol): array
    {
        return $this->client()->tickerDetails($symbol);
    }

    public function aggregates(string $symbol, int $multiplier, string $timespan, string $from, string $to, bool $adjusted, string $sort, int $limit): array
    {
        return $this->client()->aggregates($symbol, $multiplier, $timespan, $from, $to, $adjusted, $sort, $limit);
    }

    public function previousClose(string $symbol, bool $adjusted): array
    {
        return $this->client()->previousClose($symbol, $adjusted);
    }

    public function dailyOpenClose(string $symbol, string $date, bool $adjusted): array
    {
        return $this->client()->dailyOpenClose($symbol, $date, $adjusted);
    }

    public function groupedDaily(string $date, string $market, string $locale, bool $adjusted): array
    {
        return $this->client()->groupedDaily($date, $market, $locale, $adjusted);
    }

    public function lastTrade(string $symbol): array
    {
        return $this->client()->lastTrade($symbol);
    }

    public function lastQuote(string $symbol): array
    {
        return $this->client()->lastQuote($symbol);
    }

    public function snapshot(string $symbol, string $market, string $locale): array
    {
        return $this->client()->snapshotTicker($symbol, $market, $locale);
    }

    public function news(string $symbol, int $limit): array
    {
        return $this->client()->tickerNews($symbol, $limit);
    }

    public function dividends(string $symbol, int $limit): array
    {
        return $this->client()->dividends($symbol, $limit);
    }

    public function splits(string $symbol, int $limit): array
    {
        return $this->client()->splits($symbol, $limit);
    }

    public function exchanges(string $assetClass, ?string $locale = null): array
    {
        return $this->client()->exchanges($assetClass, $locale);
    }

    public function marketStatus(): array
    {
        return $this->client()->marketStatus();
    }

    private function client(): PolygonClient
    {
        if ($this->client === null) {
            throw new \RuntimeException('Polygon no está configurado', 503);
        }
        return $this->client;
    }
}
