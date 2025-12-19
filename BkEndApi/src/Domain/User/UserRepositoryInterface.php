<?php
declare(strict_types=1);

namespace FinHub\Domain\User;

interface UserRepositoryInterface
{
    public function findByEmail(string $email): ?User;
}
