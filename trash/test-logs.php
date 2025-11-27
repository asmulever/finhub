<?php
declare(strict_types=1);
	error_reporting(E_ALL);
ini_set('display_errors', '1');

try {
    run();
} catch (Throwable $e) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'FATAL ERROR:' . PHP_EOL;
    echo $e->getMessage() . PHP_EOL . PHP_EOL;
    echo $e->getTraceAsString();
}

function run(): void
{
    if (PHP_SAPI === 'cli') {
        $envBase = getenv('TEST_LOGS_BASE_URL');
        $baseUrl = $GLOBALS['argv'][1] ?? ($envBase !== false ? $envBase : 'https://finhub.42web.io/api');
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $defaultBase = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/api';
        $envBase = getenv('TEST_LOGS_BASE_URL');
        $baseUrl = $_GET['base_url'] ?? ($envBase !== false ? $envBase : $defaultBase);
        header('Content-Type: text/plain; charset=UTF-8');
    }

    if ($baseUrl === false || $baseUrl === null || $baseUrl === '') {
        $baseUrl = 'https://finhub.42web.io/api';
    }

    $baseUrl = rtrim($baseUrl, '/');

    $tests = [];
    $tests['health'] = request('GET', $baseUrl . '/health');
    $loginPayload = ['email' => 'ariel@example.com', 'password' => 'ariel.25'];
    $tests['login'] = request('POST', $baseUrl . '/auth/login', ['json' => $loginPayload]);
    $tests['session'] = request('GET', $baseUrl . '/auth/session');
    $tests['filters'] = request('GET', $baseUrl . '/logs/filters');
    $dateFrom = date('Y-m-d', strtotime('-30 days'));
    $dateTo = date('Y-m-d');
    $logsUrl = sprintf('%s/logs?date_from=%s&date_to=%s&page=1&page_size=25', $baseUrl, $dateFrom, $dateTo);
    $tests['logs'] = request('GET', $logsUrl);
    $tests['legacy_logs'] = request('GET', str_replace('/api', '', $logsUrl));

    foreach ($tests as $name => $result) {
        assertStatus(strtoupper($name), $result, 200, $name === 'legacy_logs' ? 404 : 299);
    }

    if (($tests['logs']['status'] ?? 0) === 200 && isset($tests['logs']['json']['data'])) {
        echo PHP_EOL, 'Resumen de registros devueltos:', PHP_EOL;
        echo 'Total: ', $tests['logs']['json']['pagination']['total'] ?? count($tests['logs']['json']['data']), PHP_EOL;
        foreach (array_slice($tests['logs']['json']['data'], 0, 5) as $row) {
            printf('- [%s] %s %s %s' . PHP_EOL, $row['created_at'] ?? '??', $row['level'] ?? '?', $row['route'] ?? '?', $row['message'] ?? '');
        }
    } else {
        echo PHP_EOL, "No se pudo obtener el listado de logs para analizar." . PHP_EOL;
    }
}

function request(string $method, string $url, array $options = []): array
{
    if (function_exists('curl_init')) {
        return requestWithCurl($method, $url, $options);
    }

    return requestWithStreams($method, $url, $options);
}

function requestWithCurl(string $method, string $url, array $options): array
{
    static $cookieFile;
    if ($cookieFile === null) {
        $cookieFile = tempnam(sys_get_temp_dir(), 'logs_test_cookie_');
        register_shutdown_function(static function () use (&$cookieFile): void {
            if ($cookieFile && file_exists($cookieFile)) {
                @unlink($cookieFile);
            }
        });
    }

    $ch = curl_init();
    $headers = $options['headers'] ?? [];
    if (isset($options['json'])) {
        $headers[] = 'Content-Type: application/json';
        $options['body'] = json_encode($options['json'], JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_TIMEOUT => 20,
    ]);

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    if (isset($options['body'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $options['body']);
    }

    $response = curl_exec($ch);
    if ($response === false) {
        $err = curl_error($ch);
        $code = curl_errno($ch);
        curl_close($ch);
        return ['status' => 0, 'error' => "cURL error {$code}: {$err}"];
    }

    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $statusCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headersRaw = substr($response, 0, $headerSize) ?: '';
    $body = substr($response, $headerSize) ?: '';
    curl_close($ch);

    return [
        'status' => $statusCode,
        'headers' => parseHeaders($headersRaw),
        'body' => $body,
        'json' => decodeJson($body),
    ];
}

function requestWithStreams(string $method, string $url, array $options): array
{
    static $cookieJar = [];

    $headers = $options['headers'] ?? [];
    if (isset($options['json'])) {
        $headers[] = 'Content-Type: application/json';
        $options['body'] = json_encode($options['json'], JSON_UNESCAPED_SLASHES);
    }

    $cookieHeader = buildCookieHeader($cookieJar);
    if ($cookieHeader !== '') {
        $headers[] = 'Cookie: ' . $cookieHeader;
    }

    $context = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $options['body'] ?? null,
            'ignore_errors' => true,
            'timeout' => 20,
        ],
    ];

    $body = @file_get_contents($url, false, stream_context_create($context));
    $responseHeaders = $http_response_header ?? [];
    if ($body === false) {
        return ['status' => 0, 'error' => 'file_get_contents failed'];
    }

    $statusLine = $responseHeaders[0] ?? 'HTTP/1.1 0';
    preg_match('#HTTP/\S+\s(\d{3})#', $statusLine, $matches);
    $statusCode = isset($matches[1]) ? (int)$matches[1] : 0;
    storeCookies($responseHeaders, $cookieJar);

    return [
        'status' => $statusCode,
        'headers' => parseHeaders(implode("\n", $responseHeaders)),
        'body' => $body,
        'json' => decodeJson($body),
    ];
}

function buildCookieHeader(array $jar): string
{
    if (empty($jar)) {
        return '';
    }
    $pairs = [];
    foreach ($jar as $name => $value) {
        $pairs[] = $name . '=' . $value;
    }
    return implode('; ', $pairs);
}

function storeCookies(array $headers, array &$jar): void
{
    foreach ($headers as $line) {
        if (stripos($line, 'Set-Cookie:') === 0) {
            $cookieLine = trim(substr($line, strlen('Set-Cookie:')));
            $parts = explode(';', $cookieLine);
            if (!empty($parts[0]) && str_contains($parts[0], '=')) {
                [$name, $value] = explode('=', $parts[0], 2);
                $jar[trim($name)] = trim($value);
            }
        }
    }
}

function parseHeaders(string $raw): array
{
    $headers = [];
    foreach (explode("\n", trim($raw)) as $line) {
        if (str_contains($line, ':')) {
            [$key, $value] = explode(':', $line, 2);
            $headers[strtolower(trim($key))] = trim($value);
        }
    }
    return $headers;
}

function decodeJson(string $body): mixed
{
    if ($body === '') {
        return null;
    }
    try {
        return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    } catch (Throwable) {
        return null;
    }
}

function logResult(string $title, array $result): void
{
    echo str_repeat('=', 80), PHP_EOL;
    echo $title, PHP_EOL;
    echo 'Status: ', $result['status'], PHP_EOL;
    if (isset($result['error'])) {
        echo 'Error: ', $result['error'], PHP_EOL;
        return;
    }
    if (!empty($result['json'])) {
        echo 'JSON: ', json_encode($result['json'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
    } else {
        echo 'Body: ', trim($result['body'] ?? ''), PHP_EOL;
    }
}

function assertStatus(string $title, array $result, int $expectedMin = 200, int $expectedMax = 299): void
{
    logResult($title, $result);
    if ($result['status'] < $expectedMin || $result['status'] > $expectedMax) {
        echo "--> ❌ Unexpected status for {$title}" . PHP_EOL;
    } else {
        echo "--> ✅ OK" . PHP_EOL;
    }
}
