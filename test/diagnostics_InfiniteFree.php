<?php
declare(strict_types=1);

// Standalone diagnostics utility for login troubleshooting.

function getRequestHeaders(): array
{
    if (function_exists('getallheaders')) {
        $headers = getallheaders();
        return is_array($headers) ? $headers : [];
    }

    if (function_exists('apache_request_headers')) {
        $headers = apache_request_headers();
        return is_array($headers) ? $headers : [];
    }

    $headers = [];
    foreach ($_SERVER as $key => $value) {
        if (str_starts_with($key, 'HTTP_')) {
            $normalized = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
            $headers[$normalized] = $value;
        }
    }

    return $headers;
}

function checkPathAccess(string $path): array
{
    $result = [
        'path' => $path,
        'exists' => file_exists($path),
        'is_dir' => is_dir($path),
        'readable' => is_readable($path),
        'writable' => is_writable($path),
        'write_test' => ['success' => false, 'error' => null],
        'read_test' => ['success' => false, 'error' => null],
    ];

    if (!$result['exists'] || !$result['is_dir'] || !$result['writable']) {
        $result['status'] = false;
        return $result;
    }

    $testFile = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'diag_' . uniqid('', true) . '.txt';
    $payload = 'diagnostics:' . microtime(true);

    $bytes = @file_put_contents($testFile, $payload);
    if ($bytes === false) {
        $result['write_test']['error'] = 'Unable to write test file.';
        $result['status'] = false;
        return $result;
    }

    $result['write_test']['success'] = true;

    $readContent = @file_get_contents($testFile);
    if ($readContent === false) {
        $result['read_test']['error'] = 'Unable to read test file.';
        $result['status'] = false;
        @unlink($testFile);
        return $result;
    }

    $result['read_test']['success'] = ($readContent === $payload);
    $result['status'] = $result['write_test']['success'] && $result['read_test']['success'];
    @unlink($testFile);

    return $result;
}

$headers = getRequestHeaders();
$projectRoot = dirname(__DIR__);
$tmpPath = '/tmp';
if (!is_dir($tmpPath)) {
    $tmpPath = sys_get_temp_dir();
}

$serverInfo = [
    'status' => true,
    'php_version' => PHP_VERSION,
    'memory_limit' => ini_get('memory_limit'),
    'max_execution_time' => ini_get('max_execution_time'),
    'extensions' => get_loaded_extensions(),
    'sapi' => PHP_SAPI,
    'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? null,
    'headers_detected' => array_keys($headers),
];

$filesystem = [
    'paths' => [
        'tmp' => checkPathAccess($tmpPath),
        'project_root' => checkPathAccess($projectRoot),
    ],
];
$filesystem['status'] = ($filesystem['paths']['tmp']['status'] ?? false) && ($filesystem['paths']['project_root']['status'] ?? false);

$dbHost = $_ENV['DB_HOST'] 
          ?? ($_SERVER['DB_HOST'] ?? getenv('DB_HOST') ?: 'sql208.infinityfree.com');

$dbPort = (int)($_ENV['DB_PORT'] 
          ?? ($_SERVER['DB_PORT'] ?? getenv('DB_PORT') ?: 3306));

$dbName = $_ENV['DB_DATABASE'] 
          ?? ($_SERVER['DB_DATABASE'] ?? getenv('DB_DATABASE') ?: 'if0_39913066_finhub_db');

$dbUser = $_ENV['DB_USERNAME'] 
          ?? ($_SERVER['DB_USERNAME'] ?? getenv('DB_USERNAME') ?: 'if0_39913066');

$dbPass = $_ENV['DB_PASSWORD'] 
          ?? ($_SERVER['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: 'Asmulever25');


$database = [
    'host' => $dbHost,
    'port' => $dbPort,
    'name' => $dbName,
    'pdo_available' => extension_loaded('pdo'),
    'pdo_mysql_available' => extension_loaded('pdo_mysql'),
    'connection_attempted' => false,
    'connection_successful' => false,
    'error' => null,
];

if ($database['pdo_available'] && $database['pdo_mysql_available']) {
    $database['connection_attempted'] = true;
    $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $dbName);
    try {
        $pdo = new PDO($dsn, $dbUser, $dbPass, [
            PDO::ATTR_TIMEOUT => 5,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
        $database['connection_successful'] = $pdo !== null;
    } catch (Throwable $e) {
        $database['error'] = $e->getMessage();
    }
} else {
    $database['error'] = 'PDO or PDO_MySQL extension not available.';
}
$database['status'] = $database['connection_successful'];

$rawBody = file_get_contents('php://input');
$decodedJson = null;
$jsonError = null;
if ($rawBody !== '' && $rawBody !== false) {
    $decodedJson = json_decode($rawBody, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        $jsonError = json_last_error_msg();
        $decodedJson = null;
    }
}

$authorizationHeader = $headers['Authorization'] ?? ($headers['authorization'] ?? ($_SERVER['HTTP_AUTHORIZATION'] ?? null));

$requestInfo = [
    'status' => true,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
    'uri' => $_SERVER['REQUEST_URI'] ?? null,
    'query_string' => $_SERVER['QUERY_STRING'] ?? null,
    'headers' => $headers,
    'authorization' => $authorizationHeader,
    'body' => [
        'raw' => $rawBody,
        'json' => $decodedJson,
        'json_error' => $jsonError,
    ],
    'cors_probe' => [
        'origin' => $_SERVER['HTTP_ORIGIN'] ?? null,
        'access_control_request_method' => $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'] ?? null,
        'access_control_request_headers' => $_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'] ?? null,
        'headers_received' => $headers,
    ],
];

$sessionsReport = [
    'status' => false,
    'session_enabled' => function_exists('session_status'),
    'session_state' => null,
    'session_id' => null,
    'write_value' => null,
    'read_value' => null,
    'error' => null,
];

if ($sessionsReport['session_enabled']) {
    try {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            session_start();
        }
        $sessionsReport['session_state'] = session_status();
        $sessionsReport['session_id'] = session_id();
        $key = 'diag_' . bin2hex(random_bytes(4));
        $value = 'session_test_' . microtime(true);
        $_SESSION[$key] = $value;
        $sessionsReport['write_value'] = $value;
        $sessionsReport['read_value'] = $_SESSION[$key] ?? null;
        $sessionsReport['status'] = $sessionsReport['read_value'] === $value;
    } catch (Throwable $e) {
        $sessionsReport['error'] = $e->getMessage();
    }
} else {
    $sessionsReport['error'] = 'Sessions not available in this PHP build.';
}

$hashingReport = [
    'status' => false,
    'algo' => PASSWORD_DEFAULT,
    'hash' => null,
    'verify_result' => null,
    'error' => null,
];

try {
    $plainPassword = 'diag-' . bin2hex(random_bytes(4));
    $hash = password_hash($plainPassword, PASSWORD_DEFAULT);
    if ($hash === false) {
        $hashingReport['error'] = 'password_hash returned false.';
    } else {
        $hashingReport['hash'] = $hash;
        $hashingReport['verify_result'] = password_verify($plainPassword, $hash);
        $hashingReport['status'] = $hashingReport['verify_result'] === true;
    }
} catch (Throwable $e) {
    $hashingReport['error'] = $e->getMessage();
}

$loginTest = [
    'status' => false,
    'user' => 'dummy@example.com',
    'attempt_password' => 'dummy-password',
    'hash' => null,
    'verified' => false,
    'error' => null,
];

try {
    $dummyHash = password_hash('dummy-password', PASSWORD_DEFAULT);
    if ($dummyHash === false) {
        $loginTest['error'] = 'Failed to generate dummy hash.';
    } else {
        $loginTest['hash'] = $dummyHash;
        $loginTest['verified'] = password_verify('dummy-password', $dummyHash);
        $loginTest['status'] = $loginTest['verified'] === true;
    }
} catch (Throwable $e) {
    $loginTest['error'] = $e->getMessage();
}

$report = [
    'server' => $serverInfo,
    'filesystem' => $filesystem,
    'database' => $database,
    'request' => $requestInfo,
    'sessions' => $sessionsReport,
    'hashing' => $hashingReport,
    'login_test' => $loginTest,
];

$overallOk = (
    ($filesystem['status'] ?? false) &&
    ($database['status'] ?? false) &&
    ($sessionsReport['status'] ?? false) &&
    ($hashingReport['status'] ?? false) &&
    ($loginTest['status'] ?? false)
);

$response = [
    'ok' => $overallOk,
    'timestamp' => date(DATE_ATOM),
    'report' => $report,
];

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
