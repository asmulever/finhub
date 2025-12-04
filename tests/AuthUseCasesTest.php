<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

use App\Application\Auth\Exception\InvalidCredentialsException;
use App\Application\Auth\Exception\InvalidRefreshTokenException;
use App\Application\AuthService;
use App\Application\LogService;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;
use App\Infrastructure\JwtService;

final class TestLogRepository implements LogRepositoryInterface
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

$logFile = sys_get_temp_dir() . '/finhub-auth-test.log';
$logService = new LogService(new TestLogRepository(), $logFile);
LogService::registerInstance($logService);

$tokenService = new JwtService('test-secret');
$repository = new InMemoryUserRepository();
$repository->save(new User(
    null,
    'auth@example.com',
    password_hash('secret', PASSWORD_DEFAULT),
    'admin',
    true
));

$authService = new AuthService($repository, $tokenService, 300, 604800);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

$tokens = $authService->validateCredentials('auth@example.com', 'secret');
assertTrue(isset($tokens['access_token']), 'Access token returned');
assertTrue(isset($tokens['refresh_token']), 'Refresh token returned');
assertTrue(isset($tokens['payload']['uid']), 'Payload contains user id');

$refreshed = $authService->refreshTokens($tokens['refresh_token']);
assertTrue($refreshed['payload']['uid'] === $tokens['payload']['uid'], 'Refresh keeps same user');

try {
    $authService->validateCredentials('auth@example.com', 'wrong');
    throw new RuntimeException('Expected InvalidCredentialsException');
} catch (InvalidCredentialsException $e) {
    // ok
}

try {
    $authService->refreshTokens('invalid-token');
    throw new RuntimeException('Expected InvalidRefreshTokenException');
} catch (InvalidRefreshTokenException $e) {
    // ok
}

echo "Auth use cases tests passed.\n";
