<?php
declare(strict_types=1);

namespace FinHub\Application\Analytics;

/**
 * Porta para obtener mercados de predicción desde una fuente externa.
 */
interface PredictionMarketFetcherInterface
{
    /**
     * Devuelve items normalizados de mercados de predicción.
     *
     * @return array{as_of:string,items:array<int,array<string,mixed>>}
     */
    public function fetchTrending(): array;
}
