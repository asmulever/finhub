<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\LogService;
use App\Infrastructure\Config;

final class IdentityProviderFactory
{
    private const ENV_FLAG = 'AUTH_DISABLED';

    public static function create(JwtService $jwtService): IdentityProviderInterface
    {
        $disabled = self::isAuthDisabled();

        if ($disabled) {
            return new AllowAnonymousIdentityProvider(LogService::getInstance());
        }

        return new AuthGuard($jwtService, LogService::getInstance());
    }

    private static function isAuthDisabled(): bool
    {
        $value = Config::get(self::ENV_FLAG, 'false');
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }
}
