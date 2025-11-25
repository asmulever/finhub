<?php

declare(strict_types=1);

namespace App\Domain;

interface PortfolioRepository
{
    public function createForUser(int $userId, string $name): int;

    public function findByUserId(int $userId): ?Portfolio;

    public function deleteByUserId(int $userId): void;
}
