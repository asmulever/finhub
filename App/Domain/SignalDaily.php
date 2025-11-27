<?php

declare(strict_types=1);

namespace App\Domain;

class SignalDaily
{
    public function __construct(
        private readonly int $instrumentId,
        private readonly int $calendarId,
        private readonly string $signalType,
        private readonly float $score,
        private readonly string $signalLabel,
        private readonly ?array $details = null,
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

    public function getSignalType(): string
    {
        return $this->signalType;
    }

    public function getScore(): float
    {
        return $this->score;
    }

    public function getSignalLabel(): string
    {
        return $this->signalLabel;
    }

    public function getDetails(): ?array
    {
        return $this->details;
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
            'signal_type' => $this->signalType,
            'score' => $this->score,
            'signal_label' => $this->signalLabel,
            'details' => $this->details,
            'last_updated_at' => $this->lastUpdatedAt,
        ];
    }
}

