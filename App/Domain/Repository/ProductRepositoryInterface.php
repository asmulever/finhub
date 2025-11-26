<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Product;

interface ProductRepositoryInterface
{
    /**
     * @return Product[]
     */
    public function findAll(): array;

    public function findById(int $id): ?Product;

    public function findBySku(string $sku): ?Product;

    public function save(Product $product): int;

    public function update(Product $product): void;

    public function delete(int $id): void;
}
