<?php

declare(strict_types=1);

use App\Infrastructure\Config;

if (!function_exists('load_env')) {
    function load_env(): void
    {
        Config::bootstrap();
    }
}
