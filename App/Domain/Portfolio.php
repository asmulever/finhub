<?php

declare(strict_types=1);

namespace App\Domain;

class Portfolio
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $accountId,
        private readonly string $name
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAccountId(): int
    {
        return $this->accountId;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
