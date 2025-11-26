<?php

declare(strict_types=1);

$baseDir = dirname(__DIR__) . '/';

spl_autoload_register(static function (string $class) use ($baseDir): void {
    $prefix = 'App\\';

    if (strncmp($prefix, $class, strlen($prefix)) !== 0) {
        return;
    }

    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});

require_once $baseDir . 'Infrastructure/Env.php';
