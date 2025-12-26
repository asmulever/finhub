<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

interface PriceSnapshotRepositoryInterface
{
    public function ensureTables(): void;

    public function storeSnapshot(array $snapshot): array;

    public function fetchLatest(string $symbol): ?array;

    public function fetchSeries(string $symbol, ?\DateTimeImmutable $since = null): array;
}
