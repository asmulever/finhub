<?php

declare(strict_types=1);

/**
 * PoC liviana para loguear en IOL y scrapear la tabla de CEDEARs.
 * No depende del resto del proyecto y puede ejecutarse directamente desde el navegador:
 * /tools/iol_scraper_test.php?token=<PONER_TOKEN_AQUI>
 */

const IOL_USER = 'smulever@gmail.com';
const IOL_PASS = 'Melones22!';

session_start();

if (!isset($_GET['token']) || $_GET['token'] !== 'abc123') {
    header('HTTP/1.1 403 Forbidden');
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Forbidden';
    exit;
}

if (($_POST['action'] ?? '') === 'store_fingerprint') {
    $fingerprint = trim((string)($_POST['fingerprint'] ?? ''));
    header('Content-Type: application/json; charset=UTF-8');
    if ($fingerprint === '') {
        echo json_encode(['success' => false, 'message' => 'Fingerprint vacío.']);
        exit;
    }
    $_SESSION['iol_fingerprint'] = $fingerprint;
    echo json_encode(['success' => true, 'message' => 'Fingerprint almacenado. Recargando...']);
    exit;
}

if (isset($_GET['reset_fp'])) {
    unset($_SESSION['iol_fingerprint']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?token=abc123');
    exit;
}

$startTime = microtime(true);

$storedFingerprint = $_SESSION['iol_fingerprint'] ?? null;

if ($storedFingerprint === null) {
    header('Content-Type: text/html; charset=UTF-8');
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <title>Generar Fingerprint - IOL</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                background: #0f172a;
                color: #e2e8f0;
                min-height: 100vh;
                margin: 0;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            .card {
                background: rgba(15, 23, 42, 0.85);
                border: 1px solid rgba(226, 232, 240, 0.2);
                border-radius: 20px;
                padding: 32px;
                max-width: 640px;
                width: 100%;
                box-shadow: 0 20px 40px rgba(15, 15, 40, 0.6);
            }
            button {
                border: 0;
                border-radius: 999px;
                padding: 14px 32px;
                font-size: 1rem;
                font-weight: 600;
                cursor: pointer;
                background: linear-gradient(120deg, #6366f1, #a855f7);
                color: #fff;
            }
            pre {
                background: rgba(15, 23, 42, 0.6);
                padding: 16px;
                border-radius: 12px;
            }
            a {
                color: #60a5fa;
            }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>Fingerprint requerido</h1>
            <p>
                Para que el backend pueda loguearse en IOL necesitamos obtener el fingerprint de tu navegador.
                Tocá el botón y esperá unos segundos; al terminar se recargará esta página automáticamente.
            </p>
            <button id="generateBtn">Generar fingerprint en este navegador</button>
            <p id="fpResult" style="margin-top: 16px; font-weight: 600;"></p>
            <pre>Si querés regenerarlo manualmente, visitá luego:
<a href="?token=abc123&amp;reset_fp=1">Resetear fingerprint</a></pre>
        </div>
        <script>
            (function () {
                const resultEl = document.getElementById('fpResult');
                const btn = document.getElementById('generateBtn');

                function loadScript(src) {
                    return new Promise((resolve, reject) => {
                        const s = document.createElement('script');
                        s.src = src;
                        s.onload = resolve;
                        s.onerror = reject;
                        document.head.appendChild(s);
                    });
                }

                async function sendFingerprint(value) {
                    const body = new URLSearchParams();
                    body.append('action', 'store_fingerprint');
                    body.append('fingerprint', value);

                    const response = await fetch(window.location.href, {
                        method: 'POST',
                        headers: {'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'},
                        body,
                    });

                    const data = await response.json();
                    resultEl.textContent = data.message || 'Listo';

                    if (data.success) {
                        setTimeout(() => window.location.reload(), 1500);
                    }
                }

                async function init() {
                    resultEl.textContent = 'Cargando librería...';
                    await loadScript('https://cdn.jsdelivr.net/npm/@thumbmarkjs/thumbmarkjs@latest/dist/thumbmark.umd.min.js');
                    resultEl.textContent = 'Listo. Hacé clic en el botón para generar el fingerprint.';
                    btn.disabled = false;
                }

                    btn.addEventListener('click', async () => {
                        btn.disabled = true;
                        resultEl.textContent = 'Generando fingerprint...';
                        try {
                            await ThumbmarkJS.setOption('exclude', []);
                            const fp = await ThumbmarkJS.getFingerprint();
                            await sendFingerprint(fp.thumbmark ?? fp);
                        } catch (err) {
                            console.error(err);
                            resultEl.textContent = 'Error: ' + err.message;
                            btn.disabled = false;
                        }
                    });

                init().catch((err) => {
                    console.error(err);
                    resultEl.textContent = 'No se pudo cargar ThumbmarkJS.';
                });
            })();
        </script>
    </body>
    </html>
    <?php
    exit;
}

header('Content-Type: text/html; charset=UTF-8');

$cookieFile = sys_get_temp_dir() . '/iol_cookies.txt';
if (@file_exists($cookieFile)) {
    @unlink($cookieFile);
}
touch($cookieFile);

$loginUrl = 'https://iol.invertironline.com/User/Login';
$quoteUrl = 'https://iol.invertironline.com/mercado/cotizaciones/argentina/cedears/todos';

echo '<h1>Scraper de prueba IOL &mdash; CEDEARs</h1>';
echo '<p>Cookie file: ' . htmlspecialchars($cookieFile, ENT_QUOTES, 'UTF-8') . '</p>';
echo '<p>Fingerprint en uso: <strong>' . htmlspecialchars($storedFingerprint ?? '(no definido)', ENT_QUOTES, 'UTF-8') . '</strong></p>';

try {
$loginPage = curlRequest($loginUrl, null, $cookieFile);
echo '<p>GET Login status: ' . $loginPage['status'] . '</p>';
if ($loginPage['status'] === 302 && isset($loginPage['headers']['location'])) {
    $redirectUrl = resolveUrl($loginPage['headers']['location'], $loginUrl);
    echo '<p>Redirigido a: ' . htmlspecialchars($redirectUrl, ENT_QUOTES, 'UTF-8') . '</p>';
    $loginPage = curlRequest($redirectUrl, null, $cookieFile);
echo '<p>GET Login (redirect) status: ' . $loginPage['status'] . '</p>';
}
if (!in_array($loginPage['status'], [200, 302, 404], true)) {
    throw new RuntimeException('No se pudo obtener el formulario de login (status inesperado).');
}
if ($loginPage['status'] === 404) {
    echo '<p style="color:#c2410c">Aviso: el login devuelve 404 pero incluye el formulario, continuando…</p>';
}

$loginPageUrl = $loginPage['effective_url'] ?? $loginUrl;
$formAction = extractFormAction($loginPage['body']) ?: '/Ingresar';
$postUrl = resolveUrl($formAction, $loginPageUrl);

$formFields = extractFormInputs($loginPage['body']);
if (empty($formFields)) {
    throw new RuntimeException('No se pudo leer el formulario de login.');
}

$postFields = $formFields;
$postFields['UserName'] = IOL_USER; // legacy fallback
$postFields['Usuario'] = IOL_USER;
$postFields['Password'] = IOL_PASS;

// Asegurar campos conocidos aunque no se encuentren en la página.
$postFields['UrlRedireccion'] = $postFields['UrlRedireccion'] ?? 'https://iol.invertironline.com/';
$postFields['FingerprintId'] = $storedFingerprint;

echo '<details style="margin:16px 0;"><summary style="cursor:pointer;font-weight:600;">Ver payload del POST</summary><pre>' .
    htmlspecialchars(print_r($postFields, true), ENT_QUOTES, 'UTF-8') .
    '</pre></details>';

    $loginResponse = curlRequest($postUrl, $postFields, $cookieFile, [
        'referer' => $loginPageUrl,
    ]);
    echo '<p>POST Login status: ' . $loginResponse['status'] . '</p>';
    if ($loginResponse['status'] !== 200 && $loginResponse['status'] !== 302) {
        echo '<details style="margin:16px 0;"><summary style="cursor:pointer;font-weight:600;">Ver respuesta del login</summary><pre>' .
            htmlspecialchars($loginResponse['body'], ENT_QUOTES, 'UTF-8') .
            '</pre></details>';
        throw new RuntimeException('Login fallido, revisar credenciales o cambios en el formulario.');
    }
    echo '<p>Login aparente correcto (status ' . $loginResponse['status'] . ').</p>';

    $cedearResponse = curlRequest($quoteUrl, null, $cookieFile);
    echo '<pre>CEDEAR GET status: ' . $cedearResponse['status'] .
        ' | body length: ' . strlen($cedearResponse['body']) . '</pre>';

    $rows = parseCedearTable($cedearResponse['body']);
    renderTable($rows);

    echo '<p>Total filas: ' . count($rows) . '</p>';
} catch (Throwable $e) {
    echo '<h2>Error</h2>';
    echo '<pre>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</pre>';
}

$duration = number_format(microtime(true) - $startTime, 4);
echo '<p>Tiempo total: ' . $duration . ' s</p>';

/**
 * @param array<string,mixed> $requestOptions
 * @return array{status:int,body:string,info:array,headers:array,effective_url:string}
 */
function curlRequest(string $url, ?array $postFields, string $cookieFile, array $requestOptions = []): array
{
    $ch = curl_init($url);
    $headers = [
        'User-Agent: Mozilla/5.0 (compatible; IOLScraper/1.0; +https://finhub.local)',
        'Accept: text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
    ];
    if (isset($requestOptions['referer'])) {
        $headers[] = 'Referer: ' . $requestOptions['referer'];
    }
    if (isset($requestOptions['headers']) && is_array($requestOptions['headers'])) {
        foreach ($requestOptions['headers'] as $extraHeader) {
            if (is_string($extraHeader)) {
                $headers[] = $extraHeader;
            }
        }
    }

    $curlOptions = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_COOKIEJAR => $cookieFile,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_ENCODING => '',
    ];

    if ($postFields !== null) {
        $curlOptions[CURLOPT_POST] = true;
        $curlOptions[CURLOPT_POSTFIELDS] = http_build_query($postFields);
    } else {
        $curlOptions[CURLOPT_HTTPGET] = true;
    }

    curl_setopt_array($ch, $curlOptions);
    $rawResponse = curl_exec($ch);
    if ($rawResponse === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return [
            'status' => 0,
            'body' => '',
            'info' => ['error' => $err],
            'headers' => [],
        ];
    }

    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $rawHeaders = substr((string)$rawResponse, 0, $headerSize) ?: '';
    $body = substr((string)$rawResponse, $headerSize) ?: '';

    $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $info = curl_getinfo($ch);
    curl_close($ch);

    return [
        'status' => (int)$status,
        'body' => $body,
        'info' => $info,
        'headers' => parseHeaders($rawHeaders),
        'effective_url' => $info['url'] ?? $url,
    ];
}

function extractFormAction(string $html): ?string
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query("//form[@id='ingresarForm']")->item(0);
    if (!$form instanceof DOMElement) {
        $form = $xpath->query('//form')->item(0);
    }

    if (!$form instanceof DOMElement) {
        return null;
    }

    $action = trim($form->getAttribute('action'));
    return $action !== '' ? $action : null;
}

/**
 * @return array<string,string>
 */
function extractFormInputs(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $form = $xpath->query("//form[@id='ingresarForm']")->item(0);
    if (!$form instanceof DOMElement) {
        $form = $xpath->query('//form')->item(0);
    }

    if (!$form instanceof DOMElement) {
        return [];
    }

    $fields = [];
    foreach ($xpath->query('.//input', $form) as $node) {
        if (!$node instanceof DOMElement) {
            continue;
        }
        $name = $node->getAttribute('name');
        if ($name === '') {
            continue;
        }
        $fields[$name] = $node->getAttribute('value');
    }

    return $fields;
}

/**
 * @return array<int, array{symbol:string,description:string,last_price:string,change_pct:string}>
 */
function parseCedearTable(string $html): array
{
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    $xpath = new DOMXPath($dom);
    $table = $xpath->query(
        "(//table[contains(@class,'tabla') or contains(@class,'table') or contains(@id,'cotizacion')])[1]"
    )->item(0);

    if (!$table instanceof DOMElement) {
        return [];
    }

    $rows = [];
    foreach ($xpath->query('.//tr[td]', $table) as $tr) {
        /** @var DOMNode $tr */
        $cells = $xpath->query('./td', $tr);
        if ($cells->length < 4) {
            continue;
        }

        $symbol = trim($cells->item(0)?->textContent ?? '');
        $description = trim($cells->item(1)?->textContent ?? '');
        $lastPrice = normalizePrice($cells->item(2)?->textContent ?? '');
        $changePct = normalizePrice($cells->item($cells->length - 1)?->textContent ?? '');

        if ($symbol === '') {
            continue;
        }

        $rows[] = [
            'symbol' => $symbol,
            'description' => $description,
            'last_price' => $lastPrice,
            'change_pct' => $changePct,
        ];
    }

    return $rows;
}

function normalizePrice(string $value): string
{
    $clean = trim(str_replace(["\n", "\r"], '', $value));
    $clean = preg_replace('/\s+/', ' ', $clean);
    return $clean ?? '';
}

/**
 * @return array<string,string>
 */
function parseHeaders(string $raw): array
{
    $headers = [];
    foreach (preg_split('/\r\n|\n|\r/', trim($raw)) as $line) {
        if ($line === '' || str_contains($line, 'HTTP/')) {
            continue;
        }
        $parts = explode(':', $line, 2);
        if (count($parts) === 2) {
            $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $headers;
}

function resolveUrl(string $location, string $baseUrl): string
{
    if (str_starts_with($location, 'http://') || str_starts_with($location, 'https://')) {
        return $location;
    }

    $base = parse_url($baseUrl);
    if ($base === false) {
        return $location;
    }

    $scheme = $base['scheme'] ?? 'https';
    $host = $base['host'] ?? '';
    $port = isset($base['port']) ? ':' . $base['port'] : '';

    if (str_starts_with($location, '/')) {
        return $scheme . '://' . $host . $port . $location;
    }

    $path = $base['path'] ?? '/';
    $dir = rtrim(dirname($path), '/');

    return $scheme . '://' . $host . $port . $dir . '/' . ltrim($location, '/');
}


/**
 * @param array<int, array<string,string>> $rows
 */
function renderTable(array $rows): void
{
    if (empty($rows)) {
        echo '<p>No se encontraron filas para mostrar.</p>';
        return;
    }

    echo '<table border="1" cellpadding="6" cellspacing="0">';
    echo '<thead><tr><th>Symbol</th><th>Descripción</th><th>Último</th><th>% Cambio</th></tr></thead>';
    echo '<tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        echo '<td>' . htmlspecialchars($row['symbol'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['description'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['last_price'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '<td>' . htmlspecialchars($row['change_pct'], ENT_QUOTES, 'UTF-8') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
}
