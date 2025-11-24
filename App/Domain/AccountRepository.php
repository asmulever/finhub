<?php

declare(strict_types=1);

namespace App\Domain;

interface AccountRepository
{
    public function findById(int $id): ?Account;

    /**
     * @return Account[]
     */
    public function findAll(): array;

    /**
     * @return Account[]
     */
    public function findByUserId(int $userId): array;

    public function save(Account $account): int;

    public function update(Account $account): void;

    public function delete(int $id): void;

    public function findDetailed(?int $userId = null): array;

    public function findDetailedById(int $id): ?array;
}
