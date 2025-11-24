<?php

declare(strict_types=1);

namespace App\Domain;

interface PortfolioRepository
{
    public function createForAccount(int $accountId, string $name): int;

    public function deleteByAccount(int $accountId): void;

    public function findByAccountId(int $accountId): ?Portfolio;
}
