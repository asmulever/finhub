<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\SignalDaily;

interface SignalDailyRepositoryInterface
{
    /**
     * @param SignalDaily[] $rows
     */
    public function upsertBatch(array $rows): void;

    /**
     * @return SignalDaily[]
     */
    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array;

    public function deleteOlderThanDate(string $cutoffDate): int;
}
