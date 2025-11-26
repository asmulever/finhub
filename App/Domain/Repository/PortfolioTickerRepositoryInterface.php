<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface PortfolioTickerRepositoryInterface
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public function findDetailedByBroker(int $brokerId, int $userId): array;

    public function findDetailedById(int $tickerId, int $userId): ?array;

    public function create(int $brokerId, int $financialObjectId, float $quantity, float $avgPrice, int $userId): int;

    public function update(int $tickerId, float $quantity, float $avgPrice, int $userId): bool;

    public function delete(int $tickerId, int $userId): bool;
}
