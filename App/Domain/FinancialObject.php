<?php

declare(strict_types=1);

namespace App\Domain;

class FinancialObject
{
    public function __construct(
        private readonly int $id,
        private readonly string $name,
        private readonly string $symbol,
        private readonly string $type
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'symbol' => $this->symbol,
            'type' => $this->type,
        ];
    }
}
