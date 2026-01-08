<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData\Provider;

/**
 * Contrato para proveedores de tipos FX.
 * Módulo: MarketData (Application).
 */
interface FxRateProviderInterface
{
    /**
     * Nombre del proveedor (para métricas/log).
     */
    public function name(): string;

    /**
     * Indica si el proveedor está listo para usarse.
     */
    public function isConfigured(): bool;

    /**
     * Devuelve el tipo de cambio para un par (ej. USD/EUR) con metadata opcional.
     *
     * @return array{rate:?float,at:?string,source:string}
     */
    public function rate(string $pair): array;
}
