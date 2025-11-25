<?php

declare(strict_types=1);

namespace App\Domain;

interface PortfolioTickerRepository
{
    /**
     * @return array<int, array<string,mixed>>
     */
    public function findDetailedByPortfolio(int $portfolioId, int $userId): array;

    public function findDetailedById(int $tickerId, int $userId): ?array;

    public function create(int $portfolioId, int $financialObjectId, float $quantity, float $avgPrice, int $userId): int;

    public function update(int $tickerId, float $quantity, float $avgPrice, int $userId): bool;

    public function delete(int $tickerId, int $userId): bool;
}
