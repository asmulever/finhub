<?php

declare(strict_types=1);

namespace App\Domain;

class InstrumentSourceMap
{
    public function __construct(
        private readonly ?int $id,
        private readonly int $instrumentId,
        private readonly string $source,
        private readonly string $sourceSymbol,
        private readonly ?array $extra = null,
        private readonly ?string $createdAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInstrumentId(): int
    {
        return $this->instrumentId;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceSymbol(): string
    {
        return $this->sourceSymbol;
    }

    public function getExtra(): ?array
    {
        return $this->extra;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'instrument_id' => $this->instrumentId,
            'source' => $this->source,
            'source_symbol' => $this->sourceSymbol,
            'extra' => $this->extra,
            'created_at' => $this->createdAt,
        ];
    }
}

