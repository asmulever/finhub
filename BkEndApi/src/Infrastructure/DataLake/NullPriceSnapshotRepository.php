<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\DataLake;

use FinHub\Application\DataLake\PriceSnapshotRepositoryInterface;

/**
 * Implementación nula: tablas de snapshots eliminadas.
 */
final class NullPriceSnapshotRepository implements PriceSnapshotRepositoryInterface
{
    public function ensureTables(): void
    {
        // noop: tablas eliminadas
    }

    public function storeSnapshot(array $snapshot): array
    {
        return ['success' => false, 'skipped' => true];
    }

    public function fetchLatest(string $symbol): ?array
    {
        return null;
    }

    public function fetchSeries(string $symbol, ?\DateTimeImmutable $since = null): array
    {
        return [];
    }

    public function fetchCaptureGroups(string $group = 'minute'): array
    {
        return [];
    }

    public function fetchCaptures(string $bucket, string $group = 'minute', ?string $symbol = null): array
    {
        return [];
    }
}
