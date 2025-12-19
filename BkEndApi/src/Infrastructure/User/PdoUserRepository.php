<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\User;

use FinHub\Domain\User\User;
use FinHub\Domain\User\UserRepositoryInterface;

final class PdoUserRepository implements UserRepositoryInterface
{
    private \PDO $pdo;

    public function __construct(\PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function findByEmail(string $email): ?User
    {
        $query = <<<'SQL'
SELECT id, email, role, status, password_hash
FROM users
WHERE email = :email
LIMIT 1
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute(['email' => $email]);
        $user = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($user === false) {
            return null;
        }

        return new User(
            (int) $user['id'],
            $user['email'],
            $user['role'],
            $user['status'],
            $user['password_hash']
        );
    }
}
