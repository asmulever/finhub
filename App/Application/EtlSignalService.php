<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\CalendarRepositoryInterface;
use App\Domain\Repository\IndicatorDailyRepositoryInterface;
use App\Domain\Repository\InstrumentRepositoryInterface;
use App\Domain\Repository\PriceDailyRepositoryInterface;
use App\Domain\Repository\SignalDailyRepositoryInterface;
use App\Domain\SignalDaily;

class EtlSignalService
{
    public function __construct(
        private readonly InstrumentRepositoryInterface $instrumentRepository,
        private readonly CalendarRepositoryInterface $calendarRepository,
        private readonly IndicatorDailyRepositoryInterface $indicatorRepository,
        private readonly PriceDailyRepositoryInterface $priceRepository,
        private readonly SignalDailyRepositoryInterface $signalRepository
    ) {
    }

    public function recalcSignals(?string $targetDate, int $instrumentLimit): array
    {
        $instrumentLimit = max(1, $instrumentLimit);

        $date = $targetDate;
        if ($date === null || $date === '') {
            $date = (new \DateTimeImmutable('today'))->format('Y-m-d');
        }

        $calendar = $this->calendarRepository->findLastTradingOnOrBefore($date);
        if ($calendar === null) {
            throw new \RuntimeException('No trading calendar date found for '.$date);
        }

        $calendarId = $calendar->getId() ?? 0;

        $instruments = $this->instrumentRepository->findActive($instrumentLimit, 0);

        $processed = 0;
        $signalsUpdated = 0;

        $batch = [];

        foreach ($instruments as $instrument) {
            $instrumentId = $instrument->getId() ?? 0;
            $indicator = $this->indicatorRepository->findOneByInstrumentAndCalendar($instrumentId, $calendarId);
            $price = $this->priceRepository->findOneByInstrumentAndCalendar($instrumentId, $calendarId);

            if ($indicator === null || $price === null || $price->getClose() === null) {
                $signalType = 'GLOBAL_SCORE';
                $score = 0.0;
                $label = 'ILLQ';
                $details = [
                    'reason' => 'missing_price_or_indicator',
                ];
            } else {
                $computed = $this->computeGlobalScore(
                    $price->getClose(),
                    $indicator->getSma200(),
                    $indicator->getSma50(),
                    $indicator->getRsi14(),
                    $indicator->getVolatility20()
                );
                $signalType = 'GLOBAL_SCORE';
                $score = $computed['score'];
                $label = $computed['label'];
                $details = $computed['details'];
            }

            $batch[] = new SignalDaily(
                $instrumentId,
                $calendarId,
                $signalType,
                $score,
                $label,
                $details,
                null
            );

            $processed++;
        }

        if ($batch !== []) {
            $this->signalRepository->upsertBatch($batch);
            $signalsUpdated = \count($batch);
        }

        $cutoffDate = (new \DateTimeImmutable('today'))
            ->modify('-10 years')
            ->format('Y-m-d');

        $deletedPrices = $this->priceRepository->deleteOlderThanDate($cutoffDate);
        $deletedIndicators = $this->indicatorRepository->deleteOlderThanDate($cutoffDate);
        $deletedSignals = $this->signalRepository->deleteOlderThanDate($cutoffDate);

        return [
            'calendar_date' => $calendar->getDate(),
            'calendar_id' => $calendarId,
            'instruments_processed' => $processed,
            'signals_updated' => $signalsUpdated,
            'purge' => [
                'prices_deleted' => $deletedPrices,
                'indicators_deleted' => $deletedIndicators,
                'signals_deleted' => $deletedSignals,
            ],
        ];
    }

    /**
     * @return array{score: float,label: string,details: array<string,mixed>}
     */
    private function computeGlobalScore(
        float $close,
        ?float $sma200,
        ?float $sma50,
        ?float $rsi14,
        ?float $vol20
    ): array {
        $score = 50.0;
        $details = [];

        if ($sma200 !== null) {
            $ratio200 = $close / $sma200;
            $details['ratio_200'] = $ratio200;
            if ($ratio200 > 1.05) {
                $score += 20;
            } elseif ($ratio200 > 1.0) {
                $score += 10;
            } elseif ($ratio200 < 0.95) {
                $score -= 20;
            } elseif ($ratio200 < 1.0) {
                $score -= 10;
            }
        }

        if ($sma50 !== null) {
            $ratio50 = $close / $sma50;
            $details['ratio_50'] = $ratio50;
            if ($ratio50 > 1.05) {
                $score += 10;
            } elseif ($ratio50 < 0.95) {
                $score -= 10;
            }
        }

        if ($rsi14 !== null) {
            $details['rsi_14'] = $rsi14;
            if ($rsi14 > 70.0) {
                $score += 5;
            } elseif ($rsi14 > 60.0) {
                $score += 10;
            } elseif ($rsi14 < 30.0) {
                $score -= 10;
            } elseif ($rsi14 < 40.0) {
                $score -= 5;
            }
        }

        if ($vol20 !== null) {
            $details['volatility_20'] = $vol20;
            if ($vol20 > 0.8) {
                $score -= 25;
            } elseif ($vol20 > 0.5) {
                $score -= 15;
            } elseif ($vol20 < 0.2) {
                $score += 5;
            }
        }

        $score = max(0.0, min(100.0, $score));

        if ($vol20 !== null && $vol20 > 0.8) {
            $label = 'RISKY';
        } elseif ($score >= 70.0) {
            $label = 'BUY';
        } elseif ($score >= 40.0) {
            $label = 'HOLD';
        } else {
            $label = 'SELL';
        }

        return [
            'score' => $score,
            'label' => $label,
            'details' => $details,
        ];
    }
}

