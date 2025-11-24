<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

class ServerArrayAuthorizationHeaderProvider implements AuthorizationHeaderProvider
{
    public function getAuthorizationHeader(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['Authorization'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
