<?php

declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\CalendarDate;
use App\Domain\Repository\CalendarRepositoryInterface;
use App\Infrastructure\DatabaseManager;
use DateTimeImmutable;
use PDO;

class MysqlCalendarRepository implements CalendarRepositoryInterface
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseManager::getConnection();
    }

    public function findByDate(string $date): ?CalendarDate
    {
        $stmt = $this->db->prepare('SELECT * FROM dim_calendar WHERE date = :date LIMIT 1');
        $stmt->execute(['date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    public function findRange(string $fromDate, string $toDate): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM dim_calendar WHERE date BETWEEN :from AND :to ORDER BY date ASC'
        );
        $stmt->execute([
            'from' => $fromDate,
            'to' => $toDate,
        ]);

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map([$this, 'mapRowToEntity'], $rows);
    }

    public function getOrCreateByDate(string $date, bool $isTradingDay, bool $isMonthEnd): CalendarDate
    {
        $existing = $this->findByDate($date);
        if ($existing !== null) {
            return $existing;
        }

        $dt = new DateTimeImmutable($date);
        $year = (int)$dt->format('Y');
        $month = (int)$dt->format('n');
        $day = (int)$dt->format('j');
        $weekOfYear = (int)$dt->format('W');

        $stmt = $this->db->prepare(
            'INSERT INTO dim_calendar (date, year, month, day, week_of_year, is_trading_day, is_month_end)
             VALUES (:date, :year, :month, :day, :week_of_year, :is_trading_day, :is_month_end)
             ON DUPLICATE KEY UPDATE
                is_trading_day = VALUES(is_trading_day),
                is_month_end = VALUES(is_month_end)'
        );
        $stmt->execute([
            'date' => $date,
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'week_of_year' => $weekOfYear,
            'is_trading_day' => $isTradingDay ? 1 : 0,
            'is_month_end' => $isMonthEnd ? 1 : 0,
        ]);

        $fresh = $this->findByDate($date);
        if ($fresh === null) {
            throw new \RuntimeException('Failed to insert or fetch calendar date for '.$date);
        }

        return $fresh;
    }

    public function findLastTradingOnOrBefore(string $date): ?CalendarDate
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM dim_calendar
             WHERE date <= :date AND is_trading_day = 1
             ORDER BY date DESC
             LIMIT 1'
        );
        $stmt->execute(['date' => $date]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapRowToEntity($row);
    }

    private function mapRowToEntity(array $row): CalendarDate
    {
        return new CalendarDate(
            (int)$row['id'],
            $row['date'],
            (int)$row['year'],
            (int)$row['month'],
            (int)$row['day'],
            (int)$row['week_of_year'],
            (bool)$row['is_trading_day'],
            (bool)$row['is_month_end'],
            $row['created_at'] ?? null
        );
    }
}
