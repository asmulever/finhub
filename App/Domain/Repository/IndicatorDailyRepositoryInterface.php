<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\IndicatorDaily;

interface IndicatorDailyRepositoryInterface
{
    /**
     * @param IndicatorDaily[] $rows
     */
    public function upsertBatch(array $rows): void;

    /**
     * @return IndicatorDaily[]
     */
    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array;

    public function findOneByInstrumentAndCalendar(int $instrumentId, int $calendarId): ?IndicatorDaily;

    public function deleteOlderThanDate(string $cutoffDate): int;
}
