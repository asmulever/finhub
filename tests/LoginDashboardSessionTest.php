<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

/**
 * Este test actúa como un operador que revisa el flujo Login → Dashboard.
 * Verifica que la vista de login cargue primero el helper de sesión (junto con js-cookie)
 * antes de ejecutar la lógica de autenticación, de modo que Session permanezca activo
 * y la vista de dashboard no se cierre automáticamente por falta de datos de sesión.
 */

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

$loginPath = __DIR__ . '/../index.php';
$dashboardPath = __DIR__ . '/../frontend/dashboard.html';

$loginContent = file_get_contents($loginPath);
assertTrue(is_string($loginContent), 'Pudo leerse index.php');

$sessionScriptTag = '<script src="/frontend/js/session.js?v=4" defer></script>';
$cookieScriptTag = '<script src="https://cdn.jsdelivr.net/npm/js-cookie@3.0.5/dist/js.cookie.min.js"></script>';
$loginScriptTag = '<script src="/frontend/js/login.js" defer></script>';

assertTrue(strpos($loginContent, $cookieScriptTag) !== false, 'Login debe cargar js-cookie para Session');
assertTrue(strpos($loginContent, $sessionScriptTag) !== false, 'Login debe cargar session.js para que Session exista');
assertTrue(strpos($loginContent, $loginScriptTag) !== false, 'Login mantiene su script principal');
assertTrue(
    strpos($loginContent, $sessionScriptTag) < strpos($loginContent, $loginScriptTag),
    'session.js debe preceder a login.js para que Session esté listo antes de usarlo'
);

$dashboardContent = file_get_contents($dashboardPath);
assertTrue(is_string($dashboardContent), 'Debe poderse leer dashboard.html');
assertTrue(strpos($dashboardContent, 'session.js?v=4') !== false, 'Dashboard continúa cargando el helper de sesión');

echo "LoginDashboardSessionTest passed: El operador mantiene el helper de sesión cargado antes del login.\n";
