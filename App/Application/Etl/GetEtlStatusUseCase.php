<?php

declare(strict_types=1);

namespace App\Application\Etl;

use App\Domain\Repository\EtlRunLogRepositoryInterface;
use App\Domain\EtlRun;

final class GetEtlStatusUseCase
{
    private const JOBS = [
        'INGEST_FINNHUB',
        'INGEST_RAVA',
        'NORMALIZE_PRICES',
        'CALC_INDICATORS',
        'CALC_SIGNALS',
    ];

    public function __construct(
        private readonly EtlRunLogRepositoryInterface $etlRunLogRepository,
    ) {
    }

    /**
     * @return array<int,array{job:string,status:string,started_at:string,finished_at:?string,rows_affected:int,message:?string}>
     */
    public function execute(): array
    {
        $summary = [];

        foreach (self::JOBS as $job) {
            $runs = $this->etlRunLogRepository->findRecentByJob($job, 1);
            $lastRun = $runs[0] ?? null;
            $summary[] = $this->formatRun($lastRun, $job);
        }

        return $summary;
    }

    /**
     * @param EtlRun|null $run
     * @return array{job:string,status:string,started_at:?string,finished_at:?string,rows_affected:int,message:?string}
     */
    private function formatRun(?EtlRun $run, string $job): array
    {
        if ($run === null) {
            return [
                'job' => $job,
                'status' => 'UNKNOWN',
                'started_at' => null,
                'finished_at' => null,
                'rows_affected' => 0,
                'message' => null,
            ];
        }

        return [
            'job' => $job,
            'status' => $run->getStatus(),
            'started_at' => $run->getStartedAt(),
            'finished_at' => $run->getFinishedAt(),
            'rows_affected' => $run->getRowsAffected(),
            'message' => $run->getMessage(),
        ];
    }
}
