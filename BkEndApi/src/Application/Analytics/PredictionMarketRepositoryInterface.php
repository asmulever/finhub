<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

/**
 * Persistencia de snapshots de mercados de predicción.
 */
interface PredictionMarketRepositoryInterface
{
    public function ensureTables(): void;

    /**
     * @param array<int,array<string,mixed>> $items
     * @return int snapshot id
     */
    public function storeSnapshot(string $source, string $asOf, array $items): int;

    /**
     * @return array<string,mixed>|null
     */
    public function findLatestSnapshot(string $source): ?array;

    /**
     * @return array<string,mixed>|null
     */
    public function findPreviousSnapshot(string $source, int $excludeSnapshotId): ?array;
}
