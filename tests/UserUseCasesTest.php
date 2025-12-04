<?php

declare(strict_types=1);

require_once __DIR__ . '/../App/vendor/autoload.php';

use App\Application\LogService;
use App\Application\User\CreateUserUseCase;
use App\Application\User\DeleteUserUseCase;
use App\Application\User\Exception\UserNotFoundException;
use App\Application\User\Exception\UserValidationException;
use App\Application\User\ListUsersUseCase;
use App\Application\User\UpdateUserUseCase;
use App\Domain\Repository\LogRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;

class TestLogRepository implements LogRepositoryInterface
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
        $id = $this->nextId++;
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

    public function seed(User $user): int
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
}

$logService = new LogService(new TestLogRepository(), '/tmp/finhub-test.log');
LogService::registerInstance($logService);

function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $message);
    }
}

$repository = new InMemoryUserRepository();
$listUseCase = new ListUsersUseCase($repository);
$createUseCase = new CreateUserUseCase($repository);
$updateUseCase = new UpdateUserUseCase($repository);
$deleteUseCase = new DeleteUserUseCase($repository);

$result = $createUseCase->execute([
    'email' => 'test@example.com',
    'password' => 'secret',
    'role' => 'admin',
]);
assertTrue($result['email'] === 'test@example.com', 'Email guardado');
assertTrue($result['role'] === 'admin', 'Rol guardado');

$users = $listUseCase->execute();
assertTrue(count($users) === 1, 'Debe haber un usuario listado');

try {
    $createUseCase->execute(['email' => 'bad-email', 'password' => '', 'role' => 'user']);
    throw new RuntimeException('Debería fallar con datos inválidos');
} catch (UserValidationException $e) {
    // esperado
}

$storedUser = $repository->findByEmail('test@example.com');
assertTrue($storedUser !== null, 'Usuario persiste');
$storedId = $storedUser->getId();
assertTrue(is_int($storedId), 'ID asignado');

$updateUseCase->execute($storedId, [
    'email' => 'updated@example.com',
    'password' => 'newpass',
    'role' => 'user',
]);
$updated = $repository->findById($storedId);
assertTrue($updated !== null && $updated->getEmail() === 'updated@example.com', 'Email actualizado');

try {
    $updateUseCase->execute(999, ['email'=>'a@b.com','password'=>'p','role'=>'user']);
    throw new RuntimeException('Debería arrojar UserNotFoundException');
} catch (UserNotFoundException $e) {
    // esperado
}

$deleteUseCase->execute($storedId);
assertTrue($repository->findById($storedId) === null, 'Usuario eliminado');

try {
    $deleteUseCase->execute(999);
    throw new RuntimeException('Debería arrojar UserNotFoundException');
} catch (UserNotFoundException $e) {
    // esperado
}

echo "User use cases tests passed.\n";
