<?php

declare(strict_types=1);

namespace App\Domain;

class User
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $email,
        private readonly string $passwordHash,
        private readonly string $role = 'user',
        private readonly bool $isActive = true,
    ) {
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * BC helper: keeps compatibility with older calls expecting getPassword.
     */
    public function getPassword(): string
    {
        return $this->passwordHash;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'is_active' => $this->isActive,
        ];
    }
}
