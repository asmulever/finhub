<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\InstrumentSourceMapRepositoryInterface;
use App\Domain\Repository\StagingPriceRepositoryInterface;
use App\Domain\StagingPriceRaw;
use DateTimeImmutable;

class EtlIngestService
{
    public function __construct(
        private readonly InstrumentSourceMapRepositoryInterface $instrumentSourceMapRepository,
        private readonly StagingPriceRepositoryInterface $stagingPriceRepository,
        private readonly PriceDataSourceInterface $finnhubSource,
        private readonly PriceDataSourceInterface $ravaSource
    ) {
    }

    public function ingest(string $source, ?string $fromDate, ?string $toDate, int $defaultDays): array
    {
        $source = strtoupper($source);
        if ($source !== 'RAVA' && $source !== 'FINHUB') {
            throw new \InvalidArgumentException('Unsupported source: '.$source);
        }

        $today = new \DateTimeImmutable('today');
        if ($toDate === null || $toDate === '') {
            $toDate = $today->format('Y-m-d');
        }
        if ($fromDate === null || $fromDate === '') {
            $from = $today->modify('-'.max(1, $defaultDays).' days');
            $fromDate = $from->format('Y-m-d');
        }

        $maps = $this->instrumentSourceMapRepository->findAllBySource($source);
        if ($maps === []) {
            return [
                'rows' => 0,
                'instruments' => 0,
            ];
        }

        $dataSource = $source === 'RAVA' ? $this->ravaSource : $this->finnhubSource;

        $fromDt = new DateTimeImmutable($fromDate);
        $toDt = new DateTimeImmutable($toDate);

        $rows = [];
        $rowsCount = 0;
        $instrumentCount = 0;

        foreach ($maps as $map) {
            $instrumentCount++;
            $bars = $dataSource->fetchDailyBars($map->getSourceSymbol(), $fromDt, $toDt);

            foreach ($bars as $bar) {
                $rows[] = new StagingPriceRaw(
                    null,
                    $bar->getSource(),
                    $bar->getSourceSymbol(),
                    $bar->getDate()->format('Y-m-d'),
                    $bar->getOpen(),
                    $bar->getHigh(),
                    $bar->getLow(),
                    $bar->getClose(),
                    $bar->getVolume(),
                    $bar->getRawPayloadJson(),
                    null
                );
                $rowsCount++;

                if (\count($rows) >= 500) {
                    $this->stagingPriceRepository->insertBatch($rows);
                    $rows = [];
                }
            }
        }

        if ($rows !== []) {
            $this->stagingPriceRepository->insertBatch($rows);
        }

        return [
            'rows' => $rowsCount,
            'instruments' => $instrumentCount,
        ];
    }
}
