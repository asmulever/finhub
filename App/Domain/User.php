<?php

declare(strict_types=1);

namespace App\Domain;

class User
{
    public function __construct(
        private readonly string $id,
        private readonly string $email,
        private readonly string $password,
        private readonly string $role,
    ) {
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }
}
