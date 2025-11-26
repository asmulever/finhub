<?php

declare(strict_types=1);

namespace App\Domain;

class PortfolioTicker
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $brokerId,
        private readonly int $financialObjectId,
        private readonly float $quantity,
        private readonly float $avgPrice
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBrokerId(): int
    {
        return $this->brokerId;
    }

    public function getFinancialObjectId(): int
    {
        return $this->financialObjectId;
    }

    public function getQuantity(): float
    {
        return $this->quantity;
    }

    public function getAveragePrice(): float
    {
        return $this->avgPrice;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'broker_id' => $this->brokerId,
            'financial_object_id' => $this->financialObjectId,
            'quantity' => $this->quantity,
            'avg_price' => $this->avgPrice,
        ];
    }
}
