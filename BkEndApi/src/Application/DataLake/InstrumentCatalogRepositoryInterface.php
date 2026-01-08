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
    public function upsertMany(array $items): int;

    /**
     * Upsert de un instrumento individual.
     *
     * @param array<string,mixed> $item
     */
    public function upsertOne(array $item): bool;

    /**
     * Devuelve el catálogo completo.
     *
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array;

    /**
     * Busca un símbolo puntual.
     *
     * @return array<string,mixed>|null
     */
    public function findBySymbol(string $symbol): ?array;

    /**
     * Elimina un instrumento del catálogo.
     */
    public function delete(string $symbol): bool;
}
