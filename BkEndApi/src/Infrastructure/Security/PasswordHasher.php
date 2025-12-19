<?php
namespace FinHub\Infrastructure\Security;

final class PasswordHasher
{
    public function hash(string $plain): string
    {
        return password_hash($plain, PASSWORD_DEFAULT);
    }

    public function verify(string $plain, string $hash): bool
    {
        return password_verify($plain, $hash);
    }
}
