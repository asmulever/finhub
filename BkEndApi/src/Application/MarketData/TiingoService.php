<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\MarketData\TiingoClient;

/**
 * Orquesta llamadas de demo a Tiingo (plan free).
 */
final class TiingoService
{
    private ?TiingoClient $client;

    public function __construct(?TiingoClient $client)
    {
        $this->client = $client;
    }

    public function iexTops(array $tickers): array
    {
        return $this->client()->fetchIexTops($tickers);
    }

    public function iexLast(array $tickers): array
    {
        return $this->client()->fetchIexLast($tickers);
    }

    public function dailyPrices(string $ticker, array $query = []): array
    {
        return $this->client()->fetchDailyPrices($ticker, $query);
    }

    public function dailyMetadata(string $ticker): array
    {
        return $this->client()->fetchDailyMetadata($ticker);
    }

    public function cryptoPrices(array $tickers, array $query = []): array
    {
        return $this->client()->fetchCryptoPrices($tickers, $query);
    }

    public function fxPrices(array $tickers, array $query = []): array
    {
        return $this->client()->fetchFxPrices($tickers, $query);
    }

    public function search(string $query): array
    {
        return $this->client()->search($query);
    }

    public function news(array $tickers, array $query = []): array
    {
        return $this->client()->news($tickers, $query);
    }

    private function client(): TiingoClient
    {
        if ($this->client === null) {
            throw new \RuntimeException('Tiingo no está configurado', 503);
        }
        return $this->client;
    }
}
