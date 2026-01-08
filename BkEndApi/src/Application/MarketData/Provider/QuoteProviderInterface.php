<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData\Provider;

/**
 * Contrato para proveedores de cotizaciones normalizadas.
 * Módulo: MarketData (Application).
 */
interface QuoteProviderInterface
{
    /**
     * Nombre del proveedor (coincide con config: twelvedata, eodhd, alphavantage, etc.).
     */
    public function name(): string;

    /**
     * Indica si el proveedor está listo para usarse (API key configurada, cliente válido).
     */
    public function isConfigured(): bool;

    /**
     * Devuelve una cotización normalizada.
     *
     * @return array{
     *   symbol:string,
     *   name:?string,
     *   currency:?string,
     *   open:?float,
     *   high:?float,
     *   low:?float,
     *   close:?float,
     *   previous_close:?float,
     *   asOf:?string,
     *   provider:string,
     *   payload?:array<string,mixed>
     * }
     */
    public function quote(string $symbol, ?string $exchange = null): array;

    /**
     * Devuelve un batch de cotizaciones normalizadas, indexadas por símbolo solicitado.
     *
     * @param array<int,string> $symbols
     * @return array<string,array<string,mixed>>
     */
    public function quotes(array $symbols, ?string $exchange = null): array;
}
