<?php

declare(strict_types=1);

namespace App\Application;

use DateTimeImmutable;

class PriceBarDTO
{
    public function __construct(
        private readonly string $source,
        private readonly string $sourceSymbol,
        private readonly DateTimeImmutable $date,
        private readonly ?float $open,
        private readonly ?float $high,
        private readonly ?float $low,
        private readonly ?float $close,
        private readonly ?int $volume,
        private readonly ?string $rawPayloadJson = null
    ) {
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function getSourceSymbol(): string
    {
        return $this->sourceSymbol;
    }

    public function getDate(): DateTimeImmutable
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

    public function getRawPayloadJson(): ?string
    {
        return $this->rawPayloadJson;
    }

    public function toArray(): array
    {
        return [
            'source' => $this->source,
            'source_symbol' => $this->sourceSymbol,
            'date' => $this->date->format('Y-m-d'),
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'raw_payload_json' => $this->rawPayloadJson,
        ];
    }
}

