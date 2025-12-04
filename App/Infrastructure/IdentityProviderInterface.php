<?php

declare(strict_types=1);

namespace App\Infrastructure;

interface IdentityProviderInterface
{
    /**
     * Valida la existencia de un token y devuelve el payload asociado.
     *
     * @param string $route Ruta actual para contexto.
     * @param string $origin Identificador del origen (por ejemplo, controller).
     * @return object|null
     */
    public function authorize(string $route, string $origin): ?object;

    /**
     * Extrae el token de acceso directo sin validar.
     */
    public function extractAccessToken(): ?string;
}
