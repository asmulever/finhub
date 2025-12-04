<?php

declare(strict_types=1);

namespace App\Application\Auth;

interface TokenServiceInterface
{
    /**
     * Genera un token de acceso con el payload dado.
     *
     * @param array<string, mixed> $payload
     */
    public function generateAccessToken(array $payload, int $ttlSeconds): string;

    /**
     * Genera un token de refresco con el payload dado.
     *
     * @param array<string, mixed> $payload
     */
    public function generateRefreshToken(array $payload, int $ttlSeconds): string;

    /**
     * Valida un token y retorna su payload decodificado o null si no es válido.
     */
    public function validateToken(string $token, string $expectedType = 'access'): ?object;
}
