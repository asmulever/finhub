<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

/**
 * Contrato para obtener las vistas públicas de cotizaciones de RAVA (HTML embebido).
 */
interface RavaViewsClientInterface
{
    /**
     * @return array<string,mixed>
     */
    public function fetchAcciones(): array;

    /**
     * @return array<string,mixed>
     */
    public function fetchCedears(): array;

    /**
     * @return array<string,mixed>
     */
    public function fetchBonos(): array;

    /**
     * @return array<string,mixed>
     */
    public function fetchMercadosGlobales(): array;

    /**
     * @return array<string,mixed>
     */
    public function fetchDolares(): array;
}
