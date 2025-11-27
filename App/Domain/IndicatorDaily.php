<?php

declare(strict_types=1);

namespace App\Domain;

class IndicatorDaily
{
    public function __construct(
        private readonly int $instrumentId,
        private readonly int $calendarId,
        private readonly ?float $sma20,
        private readonly ?float $sma50,
        private readonly ?float $sma200,
        private readonly ?float $rsi14,
        private readonly ?float $volatility20,
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

    public function getSma20(): ?float
    {
        return $this->sma20;
    }

    public function getSma50(): ?float
    {
        return $this->sma50;
    }

    public function getSma200(): ?float
    {
        return $this->sma200;
    }

    public function getRsi14(): ?float
    {
        return $this->rsi14;
    }

    public function getVolatility20(): ?float
    {
        return $this->volatility20;
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
            'sma_20' => $this->sma20,
            'sma_50' => $this->sma50,
            'sma_200' => $this->sma200,
            'rsi_14' => $this->rsi14,
            'volatility_20' => $this->volatility20,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}

