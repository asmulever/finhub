<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Order;

interface OrderRepositoryInterface
{
    /**
     * @return Order[]
     */
    public function findAll(): array;

    public function findById(int $id): ?Order;

    /**
     * @param int $userId
     * @return Order[]
     */
    public function findByUserId(int $userId): array;

    public function save(Order $order): int;

    public function update(Order $order): void;

    public function delete(int $id): void;
}
