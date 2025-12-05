<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

use App\Application\AuthService;
use App\Application\LogService;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;
use App\Infrastructure\JwtService;

/**
 * Esta prueba valida el flujo clave de autenticación:
 * 1. Login exitoso mediante AuthService.
 * 2. Verificación de que la vista del dashboard existe y contiene el botón de cerrar sesión.
 * 3. Validación de que la vista de login (index.php) sigue presente como destino de logout.
 */
class FlowLogRepository implements LogRepositoryInterface
{
    public function paginate(array $filters, int $page, int $pageSize): array
    {
        return ['data' => [], 'total' => 0];
    }

    public function findById(int $id): ?array
    {
        return null;
    }

    public function getFilterOptions(): array
    {
        return ['http_statuses' => [], 'levels' => [], 'routes' => []];
    }

    public function store(array $record): void
    {
        // no-op para la prueba
    }
}

final class InMemoryUserRepository implements UserRepositoryInterface
{
    /** @var array<int, User> */
    private array $storage = [];
    private int $nextId = 1;

    public function findByEmail(string $email): ?User
    {
        foreach ($this->storage as $user) {
            if ($user->getEmail() === $email) {
                return $user;
            }
        }

        return null;
    }

    public function findById(int $id): ?User
    {
        return $this->storage[$id] ?? null;
    }

    public function findAll(): array
    {
        return array_values($this->storage);
    }

    public function save(User $user): int
    {
        $id = $user->getId() ?? $this->nextId++;
        $this->storage[$id] = new User(
            $id,
            $user->getEmail(),
            $user->getPasswordHash(),
            $user->getRole(),
            $user->isActive()
        );
        return $id;
    }

    public function update(User $user): void
    {
        $id = $user->getId();
        if ($id === null || !isset($this->storage[$id])) {
            throw new RuntimeException('Usuario no encontrado');
        }
        $this->storage[$id] = $user;
    }

    public function delete(int $id): void
    {
        unset($this->storage[$id]);
    }
}

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

function assertContains(string $needle, string $haystack, string $message): void
{
    assertTrue(strpos($haystack, $needle) !== false, $message);
}

$logService = new LogService(new FlowLogRepository(), sys_get_temp_dir() . '/finhub-login-flow.log');
LogService::registerInstance($logService);

$tokenService = new JwtService('login-flow-secret');
$userRepo = new InMemoryUserRepository();
$userRepo->save(new User(
    null,
    'auth@example.com',
    password_hash('secret', PASSWORD_DEFAULT),
    'admin',
    true
));

$authService = new AuthService($userRepo, $tokenService, 300, 604800);

$tokens = $authService->validateCredentials('auth@example.com', 'secret');
assertTrue(!empty($tokens['access_token']), 'Debe generarse access token');
assertTrue(!empty($tokens['refresh_token']), 'Debe generarse refresh token');
assertTrue(isset($tokens['payload']['uid']), 'Payload debe contener UID');
assertTrue(isset($tokens['payload']['email']) && $tokens['payload']['email'] === 'auth@example.com', 'Email correcto en el payload');

$dashboardPath = __DIR__ . '/../frontend/dashboard.html';
assertTrue(is_readable($dashboardPath), 'La vista del dashboard debe existir');
$dashboardContent = file_get_contents($dashboardPath);
assertTrue(is_string($dashboardContent), 'Se debe leer dashboard.html correctamente');
assertContains('<title>Finhub | Dashboard</title>', $dashboardContent, 'La vista del dashboard debe tener el título esperado');
assertContains('data-user-action="logout"', $dashboardContent, 'El dashboard debe exponer la acción de logout');

$loginPath = __DIR__ . '/../index.php';
assertTrue(is_readable($loginPath), 'La vista de login (index.php) debe seguir siendo accesible');
$loginContent = file_get_contents($loginPath);
assertTrue(is_string($loginContent), 'Se debe leer index.php correctamente');
assertContains('<title>FinHub | Login</title>', $loginContent, 'La vista de login tiene el título esperado');

echo "Login flow test passed: login -> dashboard -> logout view.\n";
