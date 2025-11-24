<?php
// hosting-diagnostic.php
// Copiar a public_html y abrir en navegador.
// No requiere nada extra.

// Helpers
function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function boolstr($b){ return $b ? "YES" : "NO"; }
function safe_ini($k){ $v = ini_get($k); return $v === false ? null : $v; }
function has_func($name){
    return function_exists($name) && is_callable($name);
}
function is_disabled($name){
    $disabled = array_map('trim', explode(',', safe_ini('disable_functions') ?? ''));
    return in_array($name, $disabled, true);
}

$action = $_GET['action'] ?? null;
if ($action) {
    header("Content-Type: application/json; charset=utf-8");

    $out = ["ok"=>true, "action"=>$action, "time"=>date('c')];

    switch($action){

        case "env":
            $out["php"] = [
                "version" => PHP_VERSION,
                "sapi" => php_sapi_name(),
                "os" => PHP_OS_FAMILY . " / " . PHP_OS,
                "server_software" => $_SERVER['SERVER_SOFTWARE'] ?? null,
                "server_name" => $_SERVER['SERVER_NAME'] ?? null,
                "document_root" => $_SERVER['DOCUMENT_ROOT'] ?? null,
            ];
            $out["ini"] = [
                "memory_limit" => safe_ini("memory_limit"),
                "max_execution_time" => safe_ini("max_execution_time"),
                "max_input_time" => safe_ini("max_input_time"),
                "post_max_size" => safe_ini("post_max_size"),
                "upload_max_filesize" => safe_ini("upload_max_filesize"),
                "max_file_uploads" => safe_ini("max_file_uploads"),
                "default_socket_timeout" => safe_ini("default_socket_timeout"),
                "date.timezone" => safe_ini("date.timezone"),
                "allow_url_fopen" => safe_ini("allow_url_fopen"),
                "open_basedir" => safe_ini("open_basedir"),
                "disable_functions" => safe_ini("disable_functions"),
                "display_errors" => safe_ini("display_errors"),
                "error_reporting" => safe_ini("error_reporting"),
                "session.save_handler" => safe_ini("session.save_handler"),
                "session.save_path" => safe_ini("session.save_path"),
            ];
            break;

        case "extensions":
            $check = [
                "curl","openssl","mbstring","json","pdo","pdo_mysql","mysqli",
                "gd","zip","intl","sockets","fileinfo","simplexml"
            ];
            $ext = [];
            foreach($check as $e){
                $ext[$e] = extension_loaded($e);
            }
            $out["extensions"] = $ext;
            $out["pdo_drivers"] = class_exists("PDO") ? PDO::getAvailableDrivers() : [];
            break;

        case "functions":
            $funcs = ["exec","shell_exec","system","passthru","proc_open","popen","putenv","ini_set"];
            $f = [];
            foreach($funcs as $fn){
                $f[$fn] = [
                    "exists" => has_func($fn),
                    "disabled" => is_disabled($fn),
                ];
            }
            $out["functions"] = $f;
            break;

        case "fs":
            $base = __DIR__;
            $testfile = $base."/__diag_write_test.txt";
            $out["fs"] = [
                "base_dir" => $base,
                "is_writable_base" => is_writable($base),
                "temp_dir" => sys_get_temp_dir(),
                "is_writable_temp" => is_writable(sys_get_temp_dir()),
            ];
            $okWrite = @file_put_contents($testfile, "write test ".time());
            $out["fs"]["write_file_ok"] = $okWrite !== false;
            $out["fs"]["read_file_ok"] = @file_get_contents($testfile) !== false;
            $out["fs"]["unlink_ok"] = @unlink($testfile);
            break;

        case "net_fopen":
            $url = $_GET["url"] ?? "https://example.com/";
            $t0 = microtime(true);
            $data = @file_get_contents($url);
            $dt = microtime(true) - $t0;
            $out["net_fopen"] = [
                "url" => $url,
                "ok" => $data !== false,
                "ms" => (int)($dt*1000),
                "bytes" => $data !== false ? strlen($data) : 0,
                "allow_url_fopen" => safe_ini("allow_url_fopen"),
                "error" => $data === false ? (error_get_last()["message"] ?? "unknown") : null,
            ];
            break;

        case "net_curl":
            $url = $_GET["url"] ?? "https://example.com/";
            if (!extension_loaded("curl")) {
                $out["ok"] = false;
                $out["error"] = "curl extension not loaded";
                break;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
            ]);
            $t0 = microtime(true);
            $body = curl_exec($ch);
            $dt = microtime(true) - $t0;
            $err = curl_error($ch);
            $info = curl_getinfo($ch);
            curl_close($ch);

            $out["net_curl"] = [
                "url" => $url,
                "ok" => $body !== false,
                "ms" => (int)($dt*1000),
                "http_code" => $info["http_code"] ?? null,
                "bytes" => $body !== false ? strlen($body) : 0,
                "error" => $err ?: null,
            ];
            break;

        case "headers":
            header("X-Diag-Test: alive");
            $out["headers"] = [
                "sent" => [
                    "X-Diag-Test" => "alive"
                ],
                "all_response_headers" => function_exists("headers_list") ? headers_list() : []
            ];
            break;

        case "session":
            $ok = @session_start();
            $_SESSION["__diag"] = "ok_".time();
            $out["session"] = [
                "start_ok" => $ok,
                "id" => session_id(),
                "save_handler" => safe_ini("session.save_handler"),
                "save_path" => safe_ini("session.save_path"),
                "cookie_params" => session_get_cookie_params(),
                "value_roundtrip" => $_SESSION["__diag"] ?? null,
            ];
            break;

        default:
            $out["ok"] = false;
            $out["error"] = "unknown action";
    }

    echo json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
    exit;
}
?>
<!doctype html>
<html lang="es">
<head>
  <meta charset="utf-8"/>
  <title>InfinityFree Hosting Diagnostic</title>
  <style>
    body{font-family:system-ui,Segoe UI,Arial;max-width:980px;margin:20px auto;padding:0 12px}
    button{padding:8px 12px;margin:4px 0;cursor:pointer}
    input{width:100%;padding:8px}
    pre{background:#0b0b0b;color:#d0ffd0;padding:12px;border-radius:8px;overflow:auto}
    .row{display:flex;gap:8px;flex-wrap:wrap}
    .card{border:1px solid #ddd;border-radius:10px;padding:10px;margin:8px 0}
    h2,h3{margin:6px 0}
  </style>
</head>
<body>

<h2>InfinityFree Hosting Diagnostic</h2>
<p>Ejecuta pruebas reales del entorno. Los resultados salen en JSON.</p>

<div class="card">
  <h3>Tests</h3>
  <div class="row">
    <button onclick="run('env')">Entorno PHP + ini</button>
    <button onclick="run('extensions')">Extensiones + PDO drivers</button>
    <button onclick="run('functions')">Funciones deshabilitadas</button>
    <button onclick="run('fs')">Filesystem / permisos</button>
    <button onclick="run('headers')">Headers emitidos</button>
    <button onclick="run('session')">Sesiones / cookies</button>
  </div>
</div>

<div class="card">
  <h3>Red saliente</h3>
  <label>URL a probar</label>
  <input id="netUrl" value="https://example.com/"/>
  <div class="row">
    <button onclick="run('net_fopen', {url: netUrl.value})">HTTP via file_get_contents</button>
    <button onclick="run('net_curl', {url: netUrl.value})">HTTP via cURL</button>
  </div>
</div>

<div class="card">
  <h3>Salida</h3>
  <pre id="out">Listo. Elegí un test.</pre>
</div>

<script>
async function run(action, params = {}){
  const qs = new URLSearchParams({action, ...params});
  const url = location.pathname + "?" + qs.toString();
  out.textContent = "Ejecutando " + action + "...";
  try{
    const r = await fetch(url);
    const t = await r.text();
    out.textContent = t;
  }catch(e){
    out.textContent = String(e);
  }
}
</script>

</body>
</html>
