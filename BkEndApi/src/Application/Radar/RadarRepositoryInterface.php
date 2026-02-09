<?php
declare(strict_types=1);

namespace FinHub\Application\Radar;

interface RadarRepositoryInterface
{
    public function ensureTables(): void;

    /**
     * @param array<string,mixed> $snapshot
     */
    public function storeSnapshot(array $snapshot): int;

    /**
     * @return array<string,mixed>|null
     */
    public function findSnapshot(int $id): ?array;
}
