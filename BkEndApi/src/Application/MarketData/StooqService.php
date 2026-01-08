<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\MarketData\StooqClient;

/**
 * Orquesta llamadas de demo a Stooq (sin API key).
 */
final class StooqService
{
    private StooqClient $client;

    public function __construct(StooqClient $client)
    {
        $this->client = $client;
    }

    public function quotes(array $symbols): array
    {
        return $this->client->fetchQuotes($symbols);
    }

    public function history(string $symbolWithMarket, string $interval): array
    {
        return $this->client->fetchHistory($symbolWithMarket, $interval);
    }

    public function markets(): array
    {
        return $this->client->availableMarkets();
    }
}
