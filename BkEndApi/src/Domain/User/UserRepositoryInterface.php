<?php
declare(strict_types=1);

namespace FinHub\Domain\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
    public function findById(int $id): ?User;
    /** @return array<int, User> */
    public function listAll(): array;
    public function create(string $email, string $role, string $status, string $passwordHash): User;
    public function update(int $id, array $fields): ?User;
    public function delete(int $id): bool;
}
