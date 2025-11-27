<?php

declare(strict_types=1);

namespace App\Domain;

class StagingPriceRaw
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $source,
        private readonly string $sourceSymbol,
        private readonly string $date,
        private readonly ?float $open,
        private readonly ?float $high,
        private readonly ?float $low,
        private readonly ?float $close,
        private readonly ?int $volume,
        private readonly ?string $rawPayload,
        private readonly ?string $ingestedAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceSymbol(): string
    {
        return $this->sourceSymbol;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getOpen(): ?float
    {
        return $this->open;
    }

    public function getHigh(): ?float
    {
        return $this->high;
    }

    public function getLow(): ?float
    {
        return $this->low;
    }

    public function getClose(): ?float
    {
        return $this->close;
    }

    public function getVolume(): ?int
    {
        return $this->volume;
    }

    public function getRawPayload(): ?string
    {
        return $this->rawPayload;
    }

    public function getIngestedAt(): ?string
    {
        return $this->ingestedAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'source_symbol' => $this->sourceSymbol,
            'date' => $this->date,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'raw_payload' => $this->rawPayload,
            'ingested_at' => $this->ingestedAt,
        ];
    }
}

