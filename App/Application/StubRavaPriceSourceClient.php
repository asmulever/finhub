<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeInterface;

/**
 * Implementación stub para RAVA: lista para ser reemplazada por cliente HTTP real.
 */
class StubRavaPriceSourceClient implements PriceDataSourceInterface
{
    public function getSourceName(): string
    {
        return 'RAVA';
    }

    public function fetchDailyBars(string $sourceSymbol, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }
}

