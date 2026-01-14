<?php
declare(strict_types=1);

namespace FinHub\Application\Signals;

interface SignalRepositoryInterface
{
    /**
     * Devuelve las señales más recientes, opcionalmente filtradas por símbolo/especie.
     *
     * @param array<int,string>|null $symbols
     * @return array<int,array<string,mixed>>
     */
    public function findLatest(?array $symbols = null): array;

    /**
     * Persiste un lote de señales calculadas.
     *
     * @param array<int,array<string,mixed>> $signals
     */
    public function saveSignals(array $signals): void;

    /**
     * Elimina señales existentes para los símbolos indicados.
     *
     * @param array<int,string> $symbols
     */
    public function deleteBySymbols(array $symbols): void;

    /**
     * Elimina señales más antiguas que la fecha dada (para retención).
     */
    public function deleteOlderThan(\DateTimeImmutable $threshold): void;
}
