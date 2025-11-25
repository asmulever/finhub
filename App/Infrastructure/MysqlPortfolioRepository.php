<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\Portfolio;
use App\Domain\PortfolioRepository;
use PDO;

class MysqlPortfolioRepository implements PortfolioRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function createForUser(int $userId, string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO portfolios (user_id, name) VALUES (:user_id, :name)');
        $stmt->execute([
            'user_id' => $userId,
            'name' => $name,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function findByUserId(int $userId): ?Portfolio
    {
        $stmt = $this->db->prepare('SELECT id, user_id, name FROM portfolios WHERE user_id = :user_id LIMIT 1');
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Portfolio((int)$row['id'], (int)$row['user_id'], $row['name']);
    }

    public function deleteByUserId(int $userId): void
    {
        $stmt = $this->db->prepare('DELETE FROM portfolios WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
    }
}
