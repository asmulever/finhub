<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Instrument;

interface InstrumentRepositoryInterface
{
    public function findById(int $id): ?Instrument;

    public function findBySymbol(string $symbol): ?Instrument;

    public function findBySourceAndSymbol(string $source, string $sourceSymbol): ?Instrument;

    public function save(Instrument $instrument): int;

    /**
     * @return Instrument[]
     */
    public function findActive(int $limit, int $offset = 0): array;
}
