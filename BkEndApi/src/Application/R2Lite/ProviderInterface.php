<?php
declare(strict_types=1);

namespace FinHub\Application\R2Lite;

interface ProviderInterface
{
    /**
     * Obtiene velas diarias para un símbolo y rango [from, to].
     *
     * @param string $symbol símbolo normalizado (ej. AAPL, GGAL)
     * @param \DateTimeImmutable $from inicio inclusive
     * @param \DateTimeImmutable $to fin inclusive
     * @param string $category ACCIONES_AR|CEDEAR|BONO|MERCADO_GLOBAL
     * @return array<int,array<string,mixed>> cada vela con keys: symbol, as_of (ISO), open, high, low, close, volume, currency, provider
     */
    public function fetchDaily(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to, string $category): array;

    public function name(): string;
}
