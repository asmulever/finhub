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

    public function createForAccount(int $accountId, string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO portfolios (account_id, name) VALUES (:account_id, :name)');
        $stmt->execute([
            'account_id' => $accountId,
            'name' => $name,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function deleteByAccount(int $accountId): void
    {
        $stmt = $this->db->prepare('DELETE FROM portfolios WHERE account_id = :account_id');
        $stmt->execute(['account_id' => $accountId]);
    }

    public function findByAccountId(int $accountId): ?Portfolio
    {
        $stmt = $this->db->prepare('SELECT id, account_id, name FROM portfolios WHERE account_id = :account_id LIMIT 1');
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new Portfolio((int)$row['id'], (int)$row['account_id'], $row['name']);
    }
}
