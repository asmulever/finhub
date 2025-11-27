<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\PriceDaily;

interface PriceDailyRepositoryInterface
{
    /**
     * @param PriceDaily[] $rows
     */
    public function upsertBatch(array $rows): void;

    /**
     * @return PriceDaily[]
     */
    public function findByInstrumentAndDateRange(int $instrumentId, string $fromDate, string $toDate): array;

    public function findOneByInstrumentAndCalendar(int $instrumentId, int $calendarId): ?PriceDaily;

    /**
     * Borra filas con fecha < cutoffDate (usando dim_calendar).
     */
    public function deleteOlderThanDate(string $cutoffDate): int;
}
