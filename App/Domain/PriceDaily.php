<?php

declare(strict_types=1);

namespace App\Domain;

class PriceDaily
{
    public function __construct(
        private readonly int $instrumentId,
        private readonly int $calendarId,
        private readonly ?float $open,
        private readonly ?float $high,
        private readonly ?float $low,
        private readonly ?float $close,
        private readonly ?int $volume,
        private readonly ?float $adjClose,
        private readonly string $sourcePrimary,
        private readonly ?string $lastUpdatedAt = null,
    ) {
    }

    public function getInstrumentId(): int
    {
        return $this->instrumentId;
    }

    public function getCalendarId(): int
    {
        return $this->calendarId;
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

    public function getAdjClose(): ?float
    {
        return $this->adjClose;
    }

    public function getSourcePrimary(): string
    {
        return $this->sourcePrimary;
    }

    public function getLastUpdatedAt(): ?string
    {
        return $this->lastUpdatedAt;
    }

    public function toArray(): array
    {
        return [
            'instrument_id' => $this->instrumentId,
            'calendar_id' => $this->calendarId,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'adj_close' => $this->adjClose,
            'source_primary' => $this->sourcePrimary,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}

