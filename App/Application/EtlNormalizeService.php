<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\PriceDaily;
use App\Domain\Repository\CalendarRepositoryInterface;
use App\Domain\Repository\InstrumentSourceMapRepositoryInterface;
use App\Domain\Repository\PriceDailyRepositoryInterface;
use App\Domain\Repository\StagingPriceRepositoryInterface;

class EtlNormalizeService
{
    /**
     * Prioridad de fuentes: valores más altos tienen mayor prioridad.
     *
     * @var array<string,int>
     */
    private array $sourcePriority = [
        'FINHUB' => 1,
        'RAVA' => 2,
        'MERGED' => 3,
    ];

    public function __construct(
        private readonly InstrumentSourceMapRepositoryInterface $instrumentSourceMapRepository,
        private readonly CalendarRepositoryInterface $calendarRepository,
        private readonly StagingPriceRepositoryInterface $stagingPriceRepository,
        private readonly PriceDailyRepositoryInterface $priceDailyRepository,
    ) {
    }

    public function normalize(?string $fromDate, ?string $toDate, int $defaultDays, int $stagingRetentionDays): array
    {
        $today = new \DateTimeImmutable('today');
        if ($toDate === null || $toDate === '') {
            $toDate = $today->format('Y-m-d');
        }
        if ($fromDate === null || $fromDate === '') {
            $from = $today->modify('-'.max(1, $defaultDays).' days');
            $fromDate = $from->format('Y-m-d');
        }

        $stagingRows = $this->stagingPriceRepository->findByDateRange($fromDate, $toDate);

        $insertCount = 0;
        $updateCount = 0;
        $unmappedCount = 0;

        $batch = [];

        foreach ($stagingRows as $row) {
            $instrumentId = $this->instrumentSourceMapRepository
                ->resolveInstrumentId($row->getSource(), $row->getSourceSymbol());

            if ($instrumentId === null) {
                $unmappedCount++;
                continue;
            }

            $date = $row->getDate();
            $dt = new \DateTimeImmutable($date);
            $isMonthEnd = $dt->format('Y-m-t') === $dt->format('Y-m-d');

            $calendar = $this->calendarRepository->getOrCreateByDate(
                $date,
                true,
                $isMonthEnd
            );

            $existing = $this->priceDailyRepository
                ->findOneByInstrumentAndCalendar($instrumentId, $calendar->getId() ?? 0);

            $shouldUpsert = false;
            if ($existing === null) {
                $insertCount++;
                $shouldUpsert = true;
            } else {
                $existingPriority = $this->sourcePriority[$existing->getSourcePrimary()] ?? 0;
                $incomingPriority = $this->sourcePriority[strtoupper($row->getSource())] ?? 0;

                if ($incomingPriority >= $existingPriority) {
                    $updateCount++;
                    $shouldUpsert = true;
                }
            }

            if (!$shouldUpsert) {
                continue;
            }

            $batch[] = new PriceDaily(
                $instrumentId,
                $calendar->getId() ?? 0,
                $row->getOpen(),
                $row->getHigh(),
                $row->getLow(),
                $row->getClose(),
                $row->getVolume(),
                null,
                strtoupper($row->getSource()),
                null
            );

            if (\count($batch) >= 500) {
                $this->priceDailyRepository->upsertBatch($batch);
                $batch = [];
            }
        }

        if ($batch !== []) {
            $this->priceDailyRepository->upsertBatch($batch);
        }

        $cutoff = (new \DateTimeImmutable('today'))
            ->modify('-'.max(1, $stagingRetentionDays).' days')
            ->format('Y-m-d');
        $deletedStaging = $this->stagingPriceRepository->deleteOlderThan($cutoff);

        return [
            'inserted' => $insertCount,
            'updated' => $updateCount,
            'unmapped' => $unmappedCount,
            'staging_deleted' => $deletedStaging,
        ];
    }
}

