<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function findById(int $id): ?User;

    /**
     * @return User[]
     */
    public function findAll(): array;

    public function save(User $user): int;
    public function update(User $user): void;
    public function delete(int $id): void;
}
