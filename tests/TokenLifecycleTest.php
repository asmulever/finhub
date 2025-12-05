<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

use App\Application\AuthService;
use App\Application\LogService;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;
use App\Infrastructure\JwtService;
use Firebase\JWT\JWT;

final class SimpleLogRepository implements LogRepositoryInterface
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
        // no-op
    }
}

final class MemoryUserRepository implements UserRepositoryInterface
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

function assertEquals($expected, $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf('Assertion failed: %s (expected: %s, actual: %s)', $message, var_export($expected, true), var_export($actual, true)));
    }
}

$logService = new LogService(new SimpleLogRepository(), sys_get_temp_dir() . '/finhub-token-lifecycle.log');
LogService::registerInstance($logService);

$tokenService = new JwtService('token-lifecycle-secret');
$repo = new MemoryUserRepository();
$repo->save(new User(
    null,
    'auth@example.com',
    password_hash('secret', PASSWORD_DEFAULT),
    'admin',
    true
));

$sessionLength = 2;
$refreshLength = 5;
$authService = new AuthService($repo, $tokenService, $sessionLength, $refreshLength);

$tokens = $authService->validateCredentials('auth@example.com', 'secret');
assertTrue(!empty($tokens['access_token']), 'Se debe generar un access token');
assertTrue(!empty($tokens['refresh_token']), 'Se debe generar un refresh token');
assertTrue(isset($tokens['payload']['uid']), 'El payload debe contener uid');

$localStorage = [];
$localStorage['access_token'] = $tokens['access_token'];
$localStorage['refresh_token'] = $tokens['refresh_token'];
assertEquals($tokens['access_token'], $localStorage['access_token'], 'El access token debe almacenarse correctamente en localStorage');

$decodedAccess = $tokenService->validateToken($tokens['access_token'], 'access');
assertTrue($decodedAccess !== null, 'El token de acceso debe poder decodificarse inmediatamente');
assertEquals($sessionLength, $decodedAccess->exp - $decodedAccess->iat, 'La duración del access token debe coincidir con el TTL configurado');

$decodedRefresh = $tokenService->validateToken($tokens['refresh_token'], 'refresh');
assertTrue($decodedRefresh !== null, 'El refresh token debe poder decodificarse inmediatamente');
assertEquals($refreshLength, $decodedRefresh->exp - $decodedRefresh->iat, 'La duración del refresh token debe coincidir con el TTL configurado');

// Simulamos un token expirado construyendo uno con `exp` en el pasado.
$expiredPayload = (array)$decodedAccess;
$expiredPayload['exp'] = time() - 10;
$expiredPayload['iat'] = $expiredPayload['exp'] - 30;
$expiredPayload['type'] = 'access';
$expiredToken = JWT::encode($expiredPayload, 'token-lifecycle-secret', 'HS256');
$expiredResult = $tokenService->validateToken($expiredToken, 'access');
assertTrue($expiredResult === null, 'El token de acceso debe ser inválido luego de expirarse manualmente');

echo "Token lifecycle test passed: generación, almacenamiento, duración y expiración verificados.\n";
