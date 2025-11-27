<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeInterface;

/**
 * Implementación stub: no consulta Finnhub, solo devuelve array vacío.
 * Se deja como placeholder para tests o futuras extensiones.
 */
class StubFinnhubPriceSourceClient implements PriceDataSourceInterface
{
    public function getSourceName(): string
    {
        return 'FINHUB';
    }

    public function fetchDailyBars(string $sourceSymbol, DateTimeInterface $from, DateTimeInterface $to): array
    {
        return [];
    }
}

