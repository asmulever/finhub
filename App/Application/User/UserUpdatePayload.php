<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\User\Exception\UserValidationException;
use App\Domain\User;

final class UserUpdatePayload
{
    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly string $role
    ) {
    }

    public static function fromArray(array $data, User $existing): self
    {
        $email = strtolower(trim($data['email'] ?? $existing->getEmail()));
        $password = trim($data['password'] ?? '');
        $role = trim($data['role'] ?? $existing->getRole());

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new UserValidationException('Email y contraseña válidos son obligatorios.');
        }

        return new self($email, $password, $role === '' ? $existing->getRole() : $role);
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getRole(): string
    {
        return $this->role;
    }
}
