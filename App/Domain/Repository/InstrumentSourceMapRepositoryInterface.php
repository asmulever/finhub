<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\InstrumentSourceMap;

interface InstrumentSourceMapRepositoryInterface
{
    public function findBySourceAndSymbol(string $source, string $sourceSymbol): ?InstrumentSourceMap;

    public function resolveInstrumentId(string $source, string $sourceSymbol): ?int;

    public function save(InstrumentSourceMap $map): int;

    /**
     * @return InstrumentSourceMap[]
     */
    public function findAllBySource(string $source): array;
}
