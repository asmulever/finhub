<?php
/**
 * Smoke test de conexión a Redis usando Predis vendorizado.
 * Lee credenciales desde variables de entorno:
 *   REDIS_SCHEME=tls|tcp (por defecto tls)
 *   REDIS_HOST=...
 *   REDIS_PORT=...
 *   REDIS_USER=... (opcional, para ACL; se omite si está vacío)
 *   REDIS_API_KEY=... (usa esto como password si está presente)
 *   REDIS_PASS=...    (password alternativo si no hay API key)
 *   REDIS_DB=0        (entero, por defecto 0)
 *
 * Ejecución recomendada (evita open_basedir):
 * php -d open_basedir="/var/www/app2:/var/www/html:/tmp:/php_sessions:/var/www/errors" app2/NOTAS/test_redis.php
 */

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Predis\Client;

function env(string $key, ?string $default = null): ?string
{
    $value = getenv($key);
    return $value === false || $value === '' ? $default : $value;
}

$scheme = env('REDIS_SCHEME', 'tls');
$host   = env('REDIS_HOST');
$port   = env('REDIS_PORT');
$user   = env('REDIS_USER');
$db     = (int) env('REDIS_DB', '0');

// Prioridad: API key, luego password tradicional.
$password = env('REDIS_API_KEY') ?? env('REDIS_PASS') ?? env('REDIS_PASSWORD');

if (!$host || !$port || !$password) {
    fwrite(STDERR, "[ERROR] Falta definir REDIS_HOST, REDIS_PORT o REDIS_API_KEY/REDIS_PASS.\n");
    exit(1);
}

$parameters = [
    'scheme'             => $scheme,
    'host'               => $host,
    'port'               => (int) $port,
    'password'           => $password,
    'database'           => $db,
    'timeout'            => 1.0,
    'read_write_timeout' => 2.0,
    'persistent'         => true,
];

if ($user) {
    $parameters['username'] = $user;
}

try {
    $client = new Client($parameters);

    $ping = (string) $client->ping();

    $key = 'apb:test:' . uniqid('', true);
    $client->setex($key, 30, 'ok');
    $value = $client->get($key);

    $info = $client->info('memory');
    $usedMemory = $info['Memory']['used_memory_human'] ?? 'n/a';

    echo "[OK] PING => {$ping}\n";
    echo "[OK] SETEX/GET => {$value}\n";
    echo "[OK] Memory used => {$usedMemory}\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(
        STDERR,
        "[ERROR] " . $e->getMessage() . "\n"
    );
    exit(1);
}
