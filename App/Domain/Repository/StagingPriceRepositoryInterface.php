<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\StagingPriceRaw;

interface StagingPriceRepositoryInterface
{
    /**
     * @param StagingPriceRaw[] $rows
     */
    public function insertBatch(array $rows): void;

    /**
     * @return StagingPriceRaw[]
     */
    public function findByDateRange(?string $fromDate, ?string $toDate): array;

    /**
     * @return StagingPriceRaw[]
     */
    public function findByIngestedAtRange(?string $from, ?string $to): array;

    /**
     * Borra filas con date < cutoffDate.
     */
    public function deleteOlderThan(string $cutoffDate): int;
}
