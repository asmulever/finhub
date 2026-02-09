<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

/**
 * Contrato de repositorio para catálogo de instrumentos (DataLake SERVING).
 */
interface InstrumentCatalogRepositoryInterface
{
    /**
     * Upsert masivo de instrumentos normalizados.
     *
     * @param array<int,array<string,mixed>> $items
     */
    /**
     * Inserta (no sobrescribe) múltiples snapshots de catálogo con timestamp.
     *
     * @param array<int,array<string,mixed>> $items
     */
    public function upsertMany(array $items): int;

    /**
     * Inserta un snapshot individual.
     *
     * @param array<string,mixed> $item
     */
    public function upsertOne(array $item): bool;

    /**
     * Devuelve el último snapshot por símbolo (vista actual).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array;

    /**
     * Busca instrumentos filtrando por texto y metadatos, devolviendo el último snapshot por símbolo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function searchLatest(?string $query, ?string $tipo, ?string $panel, ?string $mercado, ?string $currency, int $limit, int $offset = 0): array;

    /**
     * Busca un símbolo puntual.
     *
     * @return array<string,mixed>|null
     */
    public function findBySymbol(string $symbol): ?array;

    /**
     * Elimina snapshots de un símbolo.
     */
    public function delete(string $symbol): bool;

    /**
     * Lista histórico de snapshots opcionalmente filtrado por símbolo/rango.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(?string $symbol = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?\DateTimeImmutable $capturedAt = null): array;

    /**
     * Devuelve lista de capturas (timestamp) con conteo de símbolos.
     *
     * @return array<int,array{captured_at:string,count:int}>
     */
    public function listCaptures(): array;
}
