<?php

declare(strict_types=1);

namespace App\Domain;

class Portfolio
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $userId,
        private readonly string $name
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

    public function getName(): string
    {
        return $this->name;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
        ];
    }
}
