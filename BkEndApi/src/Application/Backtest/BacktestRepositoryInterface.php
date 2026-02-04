<?php
declare(strict_types=1);

namespace FinHub\Application\Backtest;

interface BacktestRepositoryInterface
{
    /**
     * @param array<int,string> $universe
     */
    public function createBacktest(BacktestRequest $request, string $hash): int;

    /**
     * Persiste resumen, trades, equity y métricas en una transacción.
     *
     * @param array<int,array<string,mixed>> $trades
     * @param array<int,array<string,mixed>> $equity
     * @param array<string,mixed> $metrics
     */
    public function persistResults(int $backtestId, array $summary, array $trades, array $equity, array $metrics): void;

    /**
     * Marca un backtest como fallido.
     */
    public function markFailed(int $backtestId, string $message): void;

    /**
     * @return array<string,mixed>|null
     */
    public function getBacktest(int $id): ?array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getTrades(int $id): array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getEquity(int $id): array;

    /**
     * @return array<string,mixed>|null
     */
    public function getMetrics(int $id): ?array;

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getPriceSeries(string $instrumentId, \DateTimeImmutable $start, \DateTimeImmutable $end): array;
}
