<?php

declare(strict_types=1);

namespace App\Domain;

class User
{
    public function __construct(
        private readonly int $id,
        private readonly string $email,
        private readonly string $password
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function verifyPassword(string $password): bool
    {
        return password_verify($password, $this->password);
    }
}
