<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\User\Exception\UserValidationException;

final class UserCreationPayload
{
    public function __construct(
        private readonly string $email,
        private readonly string $password,
        private readonly string $role
    ) {
    }

    public static function fromArray(array $data): self
    {
        $email = strtolower(trim($data['email'] ?? ''));
        $password = trim($data['password'] ?? '');
        $role = trim($data['role'] ?? 'user');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            throw new UserValidationException('Email y contraseña válidos son obligatorios.');
        }

        return new self($email, $password, $role === '' ? 'user' : $role);
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
