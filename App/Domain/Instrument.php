<?php

declare(strict_types=1);

namespace App\Domain;

class Instrument
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $symbol,
        private readonly string $name,
        private readonly string $type,
        private readonly string $region,
        private readonly string $currency,
        private readonly bool $isActive = true,
        private readonly bool $isLocal = false,
        private readonly bool $isCedear = false,
        private readonly ?string $createdAt = null,
        private readonly ?string $updatedAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getRegion(): string
    {
        return $this->region;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isLocal(): bool
    {
        return $this->isLocal;
    }

    public function isCedear(): bool
    {
        return $this->isCedear;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?string
    {
        return $this->updatedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'symbol' => $this->symbol,
            'name' => $this->name,
            'type' => $this->type,
            'region' => $this->region,
            'currency' => $this->currency,
            'is_active' => $this->isActive,
            'is_local' => $this->isLocal,
            'is_cedear' => $this->isCedear,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}

