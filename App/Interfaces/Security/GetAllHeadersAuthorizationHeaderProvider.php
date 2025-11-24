<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

class GetAllHeadersAuthorizationHeaderProvider implements AuthorizationHeaderProvider
{
    public function getAuthorizationHeader(): ?string
    {
        if (!function_exists('getallheaders')) {
            return null;
        }

        $headers = getallheaders();
        if (!is_array($headers)) {
            return null;
        }

        foreach ($headers as $name => $value) {
            if (is_string($name) && strcasecmp($name, 'Authorization') === 0) {
                return is_array($value) ? trim((string)($value[0] ?? '')) : trim((string)$value);
            }
        }

        return null;
    }
}
