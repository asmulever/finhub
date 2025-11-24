<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

class AccessTokenExtractor
{
    public static function extract(AuthorizationHeaderProvider $provider): ?string
    {
        $header = $provider->getAuthorizationHeader();
        if (!is_string($header) || trim($header) === '') {
            return null;
        }

        if (preg_match('/Bearer\s+(\S+)/i', $header, $matches)) {
            return $matches[1];
        }

        return null;
    }
}
