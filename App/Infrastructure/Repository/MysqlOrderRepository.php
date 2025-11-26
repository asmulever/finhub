<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Order;
use App\Domain\Repository\OrderRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use App\Infrastructure\Logger;
use PDO;

class MysqlOrderRepository implements OrderRepositoryInterface
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
        $this->logger = new Logger();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, user_id, total, status, created_at FROM orders ORDER BY id DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function findById(int $id): ?Order
    {
        $stmt = $this->db->prepare('SELECT id, user_id, total, status, created_at FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function findByUserId(int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT id, user_id, total, status, created_at FROM orders WHERE user_id = :user_id ORDER BY id DESC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function save(Order $order): int
    {
        $this->assertValidOrder($order);
        $stmt = $this->db->prepare(
            'INSERT INTO orders (user_id, total, status) VALUES (:user_id, :total, :status)'
        );
        $stmt->execute([
            'user_id' => $order->getUserId(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(Order $order): void
    {
        if ($order->getId() === null) {
            throw new \InvalidArgumentException('Order ID is required for update.');
        }

        $this->assertValidOrder($order);
        $stmt = $this->db->prepare(
            'UPDATE orders
             SET user_id = :user_id, total = :total, status = :status
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $order->getId(),
            'user_id' => $order->getUserId(),
            'total' => $order->getTotal(),
            'status' => $order->getStatus(),
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM orders WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function mapRowToEntity(array $row): Order
    {
        return new Order(
            (int)$row['id'],
            (int)$row['user_id'],
            (float)$row['total'],
            $row['status'],
            $row['created_at'] ?? null
        );
    }

    private function assertValidOrder(Order $order): void
    {
        if ($order->getUserId() <= 0) {
            throw new \InvalidArgumentException('Order must belong to a user.');
        }

        if ($order->getTotal() < 0) {
            throw new \InvalidArgumentException('Order total must be zero or positive.');
        }

        if (trim($order->getStatus()) === '') {
            throw new \InvalidArgumentException('Order status is required.');
        }
    }
}
