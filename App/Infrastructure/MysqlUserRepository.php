<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\User;
use App\Domain\UserRepository;
use PDO;

class MysqlUserRepository implements UserRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function findByEmail(string $email): ?User
    {
        $stmt = $this->db->prepare('SELECT id, email, password_hash AS password, role FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data === false) {
            return null;
        }

        return new User($data['id'], $data['email'], $data['password'], $data['role']);
    }
}
