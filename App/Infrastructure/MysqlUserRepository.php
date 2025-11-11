<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\User;
use App\Domain\UserRepository;
use PDO;

class MysqlUserRepository implements UserRepository
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
        $this->logger = new Logger();
    }

    public function findByEmail(string $email): ?User
    {
        $this->logger->info("Attempting to find user by email: $email");
        try {
            $stmt = $this->db->prepare('SELECT id, email, password_hash AS password, role FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data === false) {
                $this->logger->info("User with email $email not found.");
                return null;
            }

            $this->logger->info("User with email $email found.");
            return new User($data['id'], $data['email'], $data['password'], $data['role']);
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding user by email: " . $e->getMessage());
            return null;
        }
    }
}
