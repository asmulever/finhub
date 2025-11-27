<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\CalendarDate;

interface CalendarRepositoryInterface
{
    public function findByDate(string $date): ?CalendarDate;

    /**
     * @return CalendarDate[]
     */
    public function findRange(string $fromDate, string $toDate): array;

    public function getOrCreateByDate(string $date, bool $isTradingDay, bool $isMonthEnd): CalendarDate;

    /**
     * Último día hábil (is_trading_day=1) menor o igual a $date.
     */
    public function findLastTradingOnOrBefore(string $date): ?CalendarDate;
}
