<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\IndicatorDaily;
use App\Domain\Repository\CalendarRepositoryInterface;
use App\Domain\Repository\IndicatorDailyRepositoryInterface;
use App\Domain\Repository\InstrumentRepositoryInterface;
use App\Domain\Repository\PriceDailyRepositoryInterface;

class EtlIndicatorService
{
    public function __construct(
        private readonly InstrumentRepositoryInterface $instrumentRepository,
        private readonly PriceDailyRepositoryInterface $priceDailyRepository,
        private readonly IndicatorDailyRepositoryInterface $indicatorDailyRepository,
        private readonly CalendarRepositoryInterface $calendarRepository
    ) {
    }

    public function recalcIndicators(int $daysToRecalc, int $historyDays, int $instrumentLimit): array
    {
        $daysToRecalc = max(1, $daysToRecalc);
        $historyDays = max($daysToRecalc, $historyDays);
        $instrumentLimit = max(1, $instrumentLimit);

        $today = new \DateTimeImmutable('today');
        $toDate = $today->format('Y-m-d');
        $fromDate = $today->modify('-'.$historyDays.' days')->format('Y-m-d');

        $instruments = $this->instrumentRepository->findActive($instrumentLimit, 0);
        $processedInstruments = 0;
        $rowsUpdated = 0;

        foreach ($instruments as $instrument) {
            $prices = $this->priceDailyRepository->findByInstrumentAndDateRange(
                $instrument->getId() ?? 0,
                $fromDate,
                $toDate
            );

            if ($prices === []) {
                continue;
            }

            $processedInstruments++;

            $closes = [];
            $returns = [];
            foreach ($prices as $idx => $p) {
                $closes[$idx] = $p->getClose();
                if ($idx > 0 && $closes[$idx - 1] !== null && $closes[$idx] !== null && $closes[$idx - 1] > 0.0) {
                    $returns[$idx] = log($closes[$idx] / $closes[$idx - 1]);
                } else {
                    $returns[$idx] = null;
                }
            }

            $total = \count($prices);
            $startIndex = max(0, $total - $daysToRecalc);

            $batch = [];
            for ($i = $startIndex; $i < $total; $i++) {
                $close = $closes[$i];
                if ($close === null) {
                    continue;
                }

                $sma20 = $this->computeSma($closes, $i, 20);
                $sma50 = $this->computeSma($closes, $i, 50);
                $sma200 = $this->computeSma($closes, $i, 200);
                $rsi14 = $this->computeRsi($closes, $i, 14);
                $vol20 = $this->computeVolatility($returns, $i, 20);

                $price = $prices[$i];
                $batch[] = new IndicatorDaily(
                    $price->getInstrumentId(),
                    $price->getCalendarId(),
                    $sma20,
                    $sma50,
                    $sma200,
                    $rsi14,
                    $vol20,
                    null
                );
            }

            if ($batch !== []) {
                $this->indicatorDailyRepository->upsertBatch($batch);
                $rowsUpdated += \count($batch);
            }
        }

        return [
            'instruments_processed' => $processedInstruments,
            'rows_updated' => $rowsUpdated,
        ];
    }

    private function computeSma(array $values, int $index, int $window): ?float
    {
        $count = 0;
        $sum = 0.0;
        for ($i = $index; $i > $index - $window && $i >= 0; $i--) {
            $v = $values[$i];
            if ($v === null) {
                return null;
            }
            $sum += $v;
            $count++;
        }

        if ($count < $window) {
            return null;
        }

        return $sum / $count;
    }

    private function computeRsi(array $closes, int $index, int $period): ?float
    {
        if ($index < $period) {
            return null;
        }

        $gains = 0.0;
        $losses = 0.0;
        $valid = 0;

        for ($i = $index - $period + 1; $i <= $index; $i++) {
            $prev = $closes[$i - 1];
            $curr = $closes[$i];
            if ($prev === null || $curr === null) {
                return null;
            }
            $delta = $curr - $prev;
            if ($delta > 0) {
                $gains += $delta;
            } elseif ($delta < 0) {
                $losses -= $delta;
            }
            $valid++;
        }

        if ($valid < $period || ($gains === 0.0 && $losses === 0.0)) {
            return null;
        }

        if ($losses === 0.0) {
            return 100.0;
        }

        $avgGain = $gains / $period;
        $avgLoss = $losses / $period;
        $rs = $avgGain / $avgLoss;
        return 100.0 - (100.0 / (1.0 + $rs));
    }

    private function computeVolatility(array $returns, int $index, int $window): ?float
    {
        $values = [];
        for ($i = $index; $i > $index - $window && $i >= 1; $i--) {
            $r = $returns[$i] ?? null;
            if ($r === null) {
                return null;
            }
            $values[] = $r;
        }

        if (\count($values) < $window) {
            return null;
        }

        $n = \count($values);
        $mean = array_sum($values) / $n;
        $sumSq = 0.0;
        foreach ($values as $v) {
            $sumSq += ($v - $mean) * ($v - $mean);
        }
        if ($n <= 1) {
            return null;
        }
        $stdev = sqrt($sumSq / ($n - 1));

        return $stdev * sqrt(252.0);
    }
}

