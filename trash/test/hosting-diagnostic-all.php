<?php
// hosting-diagnostic-all.php
// Ejecuta todas las pruebas en una sola corrida y devuelve reporte integral.
// Sin frameworks, PHP 8, compatible shared hosting.

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function safe_ini($k){ $v = ini_get($k); return $v === false ? null : $v; }
function has_func($name){ return function_exists($name) && is_callable($name); }
function is_disabled($name){
    $disabled = array_filter(array_map('trim', explode(',', safe_ini('disable_functions') ?? '')));
    return in_array($name, $disabled, true);
}
function timed(callable $fn){
    $t0 = microtime(true);
    $res = $fn();
    $dt = microtime(true) - $t0;
    return [$res, (int)round($dt*1000)];
}

$report = [
    "meta" => [
        "generated_at" => date('c'),
        "script" => basename(__FILE__),
        "server_time" => date('c'),
        "server_name" => $_SERVER['SERVER_NAME'] ?? null,
        "server_software" => $_SERVER['SERVER_SOFTWARE'] ?? null,
    ],
    "env" => [],
    "ini" => [],
    "extensions" => [],
    "pdo_drivers" => [],
    "functions" => [],
    "filesystem" => [],
    "network" => [],
    "headers" => [],
    "session" => [],
    "notes" => [],
];

// 1) Entorno
$report["env"] = [
    "php_version" => PHP_VERSION,
    "php_sapi" => php_sapi_name(),
    "php_os_family" => PHP_OS_FAMILY,
    "php_os" => PHP_OS,
    "document_root" => $_SERVER["DOCUMENT_ROOT"] ?? null,
    "script_dir" => __DIR__,
    "remote_addr" => $_SERVER["REMOTE_ADDR"] ?? null,
    "https" => !empty($_SERVER["HTTPS"]),
];

// 2) INI críticos
$ini_keys = [
    "memory_limit","max_execution_time","max_input_time",
    "post_max_size","upload_max_filesize","max_file_uploads",
    "default_socket_timeout","date.timezone","allow_url_fopen",
    "open_basedir","disable_functions","display_errors",
    "error_reporting","log_errors","error_log",
    "session.save_handler","session.save_path",
];
foreach($ini_keys as $k){
    $report["ini"][$k] = safe_ini($k);
}

// 3) Extensiones
$check_ext = [
    "curl","openssl","mbstring","json","pdo","pdo_mysql","mysqli",
    "gd","zip","intl","sockets","fileinfo","simplexml"
];
foreach($check_ext as $e){
    $report["extensions"][$e] = extension_loaded($e);
}
$report["pdo_drivers"] = class_exists("PDO") ? PDO::getAvailableDrivers() : [];

// 4) Funciones restringidas
$funcs = ["exec","shell_exec","system","passthru","proc_open","popen","putenv","ini_set"];
foreach($funcs as $fn){
    $report["functions"][$fn] = [
        "exists" => has_func($fn),
        "disabled" => is_disabled($fn),
    ];
}

// 5) Filesystem / permisos (en tu árbol)
$base = __DIR__;
$testfile = $base."/__diag_write_test.txt";
$fs = [
    "base_dir" => $base,
    "is_writable_base" => is_writable($base),
    "temp_dir" => sys_get_temp_dir(),
    "is_writable_temp" => is_writable(sys_get_temp_dir()),
];
$fs["write_file_ok"] = @file_put_contents($testfile, "write test ".time()) !== false;
$fs["read_file_ok"] = @file_get_contents($testfile) !== false;
$fs["unlink_ok"] = @unlink($testfile);
$report["filesystem"] = $fs;

// 6) Red saliente
$net_targets = [
    "https://example.com/",
    "https://api.ipify.org?format=json"
];

$report["network"]["allow_url_fopen"] = safe_ini("allow_url_fopen");
$report["network"]["file_get_contents"] = [];
foreach($net_targets as $u){
    [$data, $ms] = timed(function() use ($u){
        return @file_get_contents($u);
    });
    $report["network"]["file_get_contents"][] = [
        "url" => $u,
        "ok" => $data !== false,
        "ms" => $ms,
        "bytes" => $data !== false ? strlen($data) : 0,
        "error" => $data === false ? (error_get_last()["message"] ?? "unknown") : null,
    ];
}

$report["network"]["curl_available"] = extension_loaded("curl");
$report["network"]["curl"] = [];
if (extension_loaded("curl")) {
    foreach($net_targets as $u){
        [$r, $ms] = timed(function() use ($u){
            $ch = curl_init($u);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);
            return [$body, $err, $info];
        });
        [$body, $err, $info] = $r;
        $report["network"]["curl"][] = [
            "url" => $u,
            "ok" => $body !== false,
            "ms" => $ms,
            "http_code" => $info["http_code"] ?? null,
            "bytes" => $body !== false ? strlen($body) : 0,
            "error" => $err ?: null,
        ];
    }
}

// 7) Headers (lo que realmente devolvés)
header("X-Diag-All: alive");
$report["headers"]["sent_test_header"] = "X-Diag-All: alive";
$report["headers"]["response_headers_list"] = function_exists("headers_list") ? headers_list() : [];

// 8) Sesión
$session_ok = @session_start();
$_SESSION["__diag"] = "ok_".time();
$report["session"] = [
    "start_ok" => $session_ok,
    "id" => session_id(),
    "save_handler" => safe_ini("session.save_handler"),
    "save_path" => safe_ini("session.save_path"),
    "cookie_params" => session_get_cookie_params(),
    "value_roundtrip" => $_SESSION["__diag"] ?? null,
];

// 9) Notas automáticas para emulación
$notes = [];
if ($report["ini"]["open_basedir"]) $notes[] = "open_basedir activo: emular en contenedor restringiendo paths.";
if (strpos((string)$report["ini"]["disable_functions"], "exec") !== false) $notes[] = "Funciones de sistema deshabilitadas: bloquearlas con disable_functions en php.ini.";
if (($report["ini"]["allow_url_fopen"] ?? '') == '0') $notes[] = "allow_url_fopen=0: emularlo en php.ini.";
$report["notes"] = $notes;

// Output
$as_json = isset($_GET["json"]);

if ($as_json) {
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}

?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <title>InfinityFree Integral Diagnostic</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:1000px;margin:20px auto;padding:0 12px}
    pre{background:#0b0b0b;color:#d0ffd0;padding:12px;border-radius:8px;overflow:auto;font-size:12px}
    a.button{display:inline-block;padding:8px 12px;border:1px solid #333;border-radius:8px;text-decoration:none;margin:6px 0}
    .card{border:1px solid #ddd;border-radius:10px;padding:10px;margin:8px 0}
  </style>
</head>
<body>

<h2>Reporte integral Hosting (InfinityFree)</h2>
<p>Generado: <?=h($report["meta"]["generated_at"])?> | Server: <?=h($report["meta"]["server_name"])?> </p>

<div class="card">
  <a class="button" href="?json=1">Descargar JSON integral</a>
</div>

<div class="card">
  <h3>Notas para emulación local</h3>
  <ul>
    <?php foreach($report["notes"] as $n): ?>
      <li><?=h($n)?></li>
    <?php endforeach; ?>
    <?php if(!$report["notes"]): ?><li>No hay notas automáticas.</li><?php endif; ?>
  </ul>
</div>

<div class="card">
  <h3>JSON completo</h3>
  <pre><?=h(json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES))?></pre>
</div>

</body>
</html>
