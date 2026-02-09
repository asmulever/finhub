<?php
declare(strict_types=1);
require 'config/ApplicationBootstrap.php';
require 'autoload.php';
$bootstrap = new FinHub\Infrastructure\Config\ApplicationBootstrap(__DIR__.'/..');
$container = $bootstrap->createContainer();
$client = $container->get('moonshot_client');
try {
    $models = $client->listModels();
    var_export($models);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERR: '.$e->getMessage()."\n");
    exit(1);
}
