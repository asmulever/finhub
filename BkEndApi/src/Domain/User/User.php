<?php
declare(strict_types=1);

namespace FinHub\Domain\User;

final class User
{
    private int $id;
    private string $email;
    private string $role;
    private string $status;
    private string $passwordHash;

    public function __construct(int $id, string $email, string $role, string $status, string $passwordHash)
    {
        $this->id = $id;
        $this->email = $email;
        $this->role = $role;
        $this->status = $status;
        $this->passwordHash = $passwordHash;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getRole(): string
    {
        return $this->role;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function toResponse(): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role,
            'status' => $this->status,
        ];
    }
}
