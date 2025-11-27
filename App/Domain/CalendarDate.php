<?php

declare(strict_types=1);

namespace App\Domain;

class CalendarDate
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $date,
        private readonly int $year,
        private readonly int $month,
        private readonly int $day,
        private readonly int $weekOfYear,
        private readonly bool $isTradingDay,
        private readonly bool $isMonthEnd,
        private readonly ?string $createdAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDate(): string
    {
        return $this->date;
    }

    public function getYear(): int
    {
        return $this->year;
    }

    public function getMonth(): int
    {
        return $this->month;
    }

    public function getDay(): int
    {
        return $this->day;
    }

    public function getWeekOfYear(): int
    {
        return $this->weekOfYear;
    }

    public function isTradingDay(): bool
    {
        return $this->isTradingDay;
    }

    public function isMonthEnd(): bool
    {
        return $this->isMonthEnd;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'date' => $this->date,
            'year' => $this->year,
            'month' => $this->month,
            'day' => $this->day,
            'week_of_year' => $this->weekOfYear,
            'is_trading_day' => $this->isTradingDay,
            'is_month_end' => $this->isMonthEnd,
            'created_at' => $this->createdAt,
        ];
    }
}

