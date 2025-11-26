<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Account;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlAccountRepository implements AccountRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function findById(int $id): ?Account
    {
        $stmt = $this->db->prepare('SELECT id, user_id, broker_name, currency, is_primary FROM accounts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapRowToAccount($row);
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, user_id, broker_name, currency, is_primary FROM accounts ORDER BY id DESC');
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapRowToAccount($row);
        }
        return $results;
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare('SELECT id, user_id, broker_name, currency, is_primary FROM accounts WHERE user_id = :user_id ORDER BY id DESC');
        $stmt->execute(['user_id' => $userId]);
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = $this->mapRowToAccount($row);
        }
        return $results;
    }

    public function save(Account $account): int
    {
        $stmt = $this->db->prepare('INSERT INTO accounts (user_id, broker_name, currency, is_primary) VALUES (:user_id, :broker_name, :currency, :is_primary)');
        $stmt->execute([
            'user_id' => $account->getUserId(),
            'broker_name' => $account->getBrokerName(),
            'currency' => $account->getCurrency(),
            'is_primary' => $account->isPrimary() ? 1 : 0,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(Account $account): void
    {
        $stmt = $this->db->prepare('UPDATE accounts SET user_id = :user_id, broker_name = :broker_name, currency = :currency, is_primary = :is_primary WHERE id = :id');
        $stmt->execute([
            'id' => $account->getId(),
            'user_id' => $account->getUserId(),
            'broker_name' => $account->getBrokerName(),
            'currency' => $account->getCurrency(),
            'is_primary' => $account->isPrimary() ? 1 : 0,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM accounts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function findDetailed(?int $userId = null): array
    {
        $sql = 'SELECT a.id, a.user_id, a.broker_name, a.currency, a.is_primary, a.created_at, u.email AS user_email
                FROM accounts a
                JOIN users u ON u.id = a.user_id';

        $params = [];
        if ($userId !== null) {
            $sql .= ' WHERE a.user_id = :user_id';
            $params['user_id'] = $userId;
        }

        $sql .= ' ORDER BY a.id DESC';

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map([$this, 'formatDetailedRow'], $rows);
    }

    public function findDetailedById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT a.id, a.user_id, a.broker_name, a.currency, a.is_primary, a.created_at, u.email AS user_email
            FROM accounts a
            JOIN users u ON u.id = a.user_id
            WHERE a.id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->formatDetailedRow($row) : null;
    }

    private function mapRowToAccount(array $row): Account
    {
        return new Account(
            (int)$row['id'],
            (int)$row['user_id'],
            $row['broker_name'],
            $row['currency'],
            (bool)$row['is_primary']
        );
    }

    private function formatDetailedRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'user_id' => (int)$row['user_id'],
            'user_email' => $row['user_email'],
            'broker_name' => $row['broker_name'],
            'currency' => $row['currency'],
            'is_primary' => (bool)$row['is_primary'],
            'created_at' => $row['created_at'],
        ];
    }
}
