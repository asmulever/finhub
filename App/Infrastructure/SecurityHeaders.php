<?php

declare(strict_types=1);

if (!function_exists('apply_security_headers')) {
    function apply_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Frame-Options: DENY');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header(
            "Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/; "
            . "style-src 'self' https://cdn.jsdelivr.net https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/ https://fonts.googleapis.com 'unsafe-inline'; "
            . "img-src 'self' data: blob: https://cdn.jsdelivr.net; "
            . "font-src 'self' https://cdn.jsdelivr.net https://fonts.gstatic.com; "
            . "connect-src 'self'; "
        );
    }
}
