<?php

declare(strict_types=1);

namespace App\Domain;

class Order
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly float $total,
        private readonly string $status = 'pending',
        private readonly ?string $createdAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getTotal(): float
    {
        return $this->total;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'total' => $this->total,
            'status' => $this->status,
            'created_at' => $this->createdAt,
        ];
    }
}
