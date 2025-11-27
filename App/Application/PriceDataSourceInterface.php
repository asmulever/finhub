<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeInterface;

interface PriceDataSourceInterface
{
    public function getSourceName(): string;

    /**
     * @return PriceBarDTO[]
     */
    public function fetchDailyBars(string $sourceSymbol, DateTimeInterface $from, DateTimeInterface $to): array;
}

