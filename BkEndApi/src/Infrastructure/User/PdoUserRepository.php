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

    public function findById(int $id): ?User
    {
        $query = <<<'SQL'
SELECT id, email, role, status, password_hash
FROM users
WHERE id = :id
LIMIT 1
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute(['id' => $id]);
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

    public function listAll(): array
    {
        $query = <<<'SQL'
SELECT id, email, role, status, password_hash
FROM users
ORDER BY id ASC
SQL;
        $statement = $this->pdo->query($query);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        $users = [];
        foreach ($rows as $row) {
            $users[] = new User(
                (int) $row['id'],
                $row['email'],
                $row['role'],
                $row['status'],
                $row['password_hash']
            );
        }
        return $users;
    }

    public function create(string $email, string $role, string $status, string $passwordHash): User
    {
        $query = <<<'SQL'
INSERT INTO users (email, role, status, password_hash)
VALUES (:email, :role, :status, :password_hash)
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute([
            'email' => $email,
            'role' => $role,
            'status' => $status,
            'password_hash' => $passwordHash,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        return new User($id, $email, $role, $status, $passwordHash);
    }

    public function update(int $id, array $fields): ?User
    {
        $allowed = [
            'email' => 'email',
            'role' => 'role',
            'status' => 'status',
            'password_hash' => 'password_hash',
        ];
        $sets = [];
        $params = ['id' => $id];
        foreach ($allowed as $key => $column) {
            if (!array_key_exists($key, $fields)) {
                continue;
            }
            $sets[] = sprintf('%s = :%s', $column, $key);
            $params[$key] = $fields[$key];
        }
        if (empty($sets)) {
            return $this->findById($id);
        }

        $query = sprintf('UPDATE users SET %s WHERE id = :id', implode(', ', $sets));
        $statement = $this->pdo->prepare($query);
        $statement->execute($params);
        return $this->findById($id);
    }

    public function delete(int $id): bool
    {
        $query = <<<'SQL'
DELETE FROM users
WHERE id = :id
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute(['id' => $id]);
        return $statement->rowCount() > 0;
    }
}
