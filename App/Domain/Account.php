<?php

declare(strict_types=1);

namespace App\Domain;

class Account
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly string $brokerName,
        private readonly string $currency,
        private readonly bool $isPrimary,
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

    public function getBrokerName(): string
    {
        return $this->brokerName;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'broker_name' => $this->brokerName,
            'currency' => $this->currency,
            'is_primary' => $this->isPrimary,
        ];
    }
}
