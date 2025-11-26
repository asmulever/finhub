<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Product;
use App\Domain\Repository\ProductRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use PDO;

class MysqlProductRepository implements ProductRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, name, sku, price, is_active, created_at FROM products ORDER BY id DESC');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function findById(int $id): ?Product
    {
        $stmt = $this->db->prepare('SELECT id, name, sku, price, is_active, created_at FROM products WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function findBySku(string $sku): ?Product
    {
        $stmt = $this->db->prepare('SELECT id, name, sku, price, is_active, created_at FROM products WHERE sku = :sku LIMIT 1');
        $stmt->execute(['sku' => $sku]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function save(Product $product): int
    {
        $this->assertValidProduct($product);
        $stmt = $this->db->prepare(
            'INSERT INTO products (name, sku, price, is_active) VALUES (:name, :sku, :price, :is_active)'
        );
        $stmt->execute([
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => $product->getPrice(),
            'is_active' => $product->isActive() ? 1 : 0,
        ]);

        return (int)$this->db->lastInsertId();
    }

    public function update(Product $product): void
    {
        if ($product->getId() === null) {
            throw new \InvalidArgumentException('Product ID is required for update.');
        }

        $this->assertValidProduct($product);
        $stmt = $this->db->prepare(
            'UPDATE products
             SET name = :name, sku = :sku, price = :price, is_active = :is_active
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $product->getId(),
            'name' => $product->getName(),
            'sku' => $product->getSku(),
            'price' => $product->getPrice(),
            'is_active' => $product->isActive() ? 1 : 0,
        ]);
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function mapRowToEntity(array $row): Product
    {
        return new Product(
            (int)$row['id'],
            $row['name'],
            $row['sku'],
            (float)$row['price'],
            (bool)$row['is_active'],
            $row['created_at'] ?? null
        );
    }

    private function assertValidProduct(Product $product): void
    {
        if (trim($product->getName()) === '') {
            throw new \InvalidArgumentException('Product name is required.');
        }
        if (trim($product->getSku()) === '') {
            throw new \InvalidArgumentException('Product SKU is required.');
        }
        if ($product->getPrice() < 0) {
            throw new \InvalidArgumentException('Product price must be zero or positive.');
        }
    }
}
