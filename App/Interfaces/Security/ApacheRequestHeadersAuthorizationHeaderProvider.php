<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

class ApacheRequestHeadersAuthorizationHeaderProvider implements AuthorizationHeaderProvider
{
    public function getAuthorizationHeader(): ?string
    {
        if (!function_exists('apache_request_headers')) {
            return null;
        }

        $headers = apache_request_headers();
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
