<?php
spl_autoload_register(function (string $class) {
    $prefix = 'FinHub\\';
    $baseDir = __DIR__ . '/src/';
    if (strpos($class, $prefix) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
