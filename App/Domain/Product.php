<?php

declare(strict_types=1);

namespace App\Domain;

class Product
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $name,
        private readonly string $sku,
        private readonly float $price,
        private readonly bool $isActive = true,
        private readonly ?string $createdAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPrice(): float
    {
        return $this->price;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'sku' => $this->sku,
            'price' => $this->price,
            'is_active' => $this->isActive,
            'created_at' => $this->createdAt,
        ];
    }
}
