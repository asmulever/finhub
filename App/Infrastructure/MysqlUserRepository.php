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
            $stmt = $this->db->prepare('SELECT id, email, password_hash AS password, role, is_active FROM users WHERE email = :email LIMIT 1');
            $stmt->execute(['email' => $email]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data === false) {
                $this->logger->info("User with email $email not found.");
                return null;
            }

            $this->logger->info("User with email $email found.");
            return new User((int)$data['id'], $data['email'], $data['password'], $data['role'], (bool)$data['is_active']);
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding user by email: " . $e->getMessage());
            return null;
        }
    }

    public function findById(int $id): ?User
    {
        $this->logger->info("Attempting to find user by id: $id");
        try {
            $stmt = $this->db->prepare('SELECT id, email, password_hash AS password, role, is_active FROM users WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($data === false) {
                $this->logger->info("User with id $id not found.");
                return null;
            }

            $this->logger->info("User with id $id found.");
            return new User((int)$data['id'], $data['email'], $data['password'], $data['role'], (bool)$data['is_active']);
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding user by id: " . $e->getMessage());
            return null;
        }
    }

    public function findAll(): array
    {
        $this->logger->info("Attempting to find all users");
        try {
            $stmt = $this->db->query('SELECT id, email, password_hash AS password, role, is_active FROM users');
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = new User((int)$row['id'], $row['email'], $row['password'], $row['role'], (bool)$row['is_active']);
            }
            $this->logger->info("Found " . count($results) . " users.");
            return $results;
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding all users: " . $e->getMessage());
            return [];
        }
    }

    public function save(User $user): int
    {
        $this->logger->info("Attempting to save user: " . $user->getEmail());
        try {
            $stmt = $this->db->prepare('INSERT INTO users (email, password_hash, role, is_active) VALUES (:email, :password, :role, :is_active)');
            $stmt->execute([
                'email' => $user->getEmail(),
                'password' => $user->getPasswordHash(),
                'role' => $user->getRole(),
                'is_active' => $user->isActive() ? 1 : 0,
            ]);
            $this->logger->info("User saved successfully.");
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            $this->logger->error("Database error while saving user: " . $e->getMessage());
            throw $e;
        }
    }

    public function update(User $user): void
    {
        $this->logger->info("Attempting to update user: " . $user->getId());
        try {
            $stmt = $this->db->prepare('UPDATE users SET email = :email, password_hash = :password, role = :role, is_active = :is_active WHERE id = :id');
            $stmt->execute([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'password' => $user->getPasswordHash(),
                'role' => $user->getRole(),
                'is_active' => $user->isActive() ? 1 : 0,
            ]);
            $this->logger->info("User updated successfully.");
        } catch (\PDOException $e) {
            $this->logger->error("Database error while updating user: " . $e->getMessage());
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->logger->info("Attempting to delete user: $id");
        try {
            $stmt = $this->db->prepare('DELETE FROM users WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->logger->info("User deleted successfully.");
        } catch (\PDOException $e) {
            $this->logger->error("Database error while deleting user: " . $e->getMessage());
            throw $e;
        }
    }
}
