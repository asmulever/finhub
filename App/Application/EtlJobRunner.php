<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\EtlRun;
use App\Domain\Repository\EtlRunLogRepositoryInterface;
use App\Infrastructure\RequestContext;

class EtlJobResult
{
    public function __construct(
        private readonly string $job,
        private readonly string $status,
        private readonly string $startedAt,
        private readonly string $finishedAt,
        private readonly int $rowsAffected,
        private readonly string $message
    ) {
    }

    public function toArray(): array
    {
        return [
            'job' => $this->job,
            'status' => $this->status,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'rows_affected' => $this->rowsAffected,
            'message' => $this->message,
        ];
    }
}

class EtlJobRunner
{
    public function __construct(
        private readonly EtlRunLogRepositoryInterface $etlRunLogRepository,
        private readonly LogService $logger
    ) {
    }

    /**
     * @param callable(EtlRun): int $callback
     */
    public function runJob(string $jobName, callable $callback): EtlJobResult
    {
        $run = $this->etlRunLogRepository->startRun($jobName);

        try {
            $rowsAffected = (int)$callback($run);
            $finishedRun = $this->etlRunLogRepository->finishRun(
                $run,
                'OK',
                $rowsAffected,
                'Completed successfully'
            );

            return new EtlJobResult(
                $jobName,
                'OK',
                $finishedRun->getStartedAt(),
                $finishedRun->getFinishedAt() ?? $finishedRun->getStartedAt(),
                $finishedRun->getRowsAffected(),
                'Completed successfully'
            );
        } catch (\Throwable $e) {
            $this->logger->logException($e, 500, [
                'origin' => 'etl-job-runner',
                'route' => RequestContext::getRoute(),
                'job_name' => $jobName,
            ]);

            $finishedRun = $this->etlRunLogRepository->finishRun(
                $run,
                'ERROR',
                0,
                mb_substr($e->getMessage(), 0, 500)
            );

            return new EtlJobResult(
                $jobName,
                'ERROR',
                $finishedRun->getStartedAt(),
                $finishedRun->getFinishedAt() ?? $finishedRun->getStartedAt(),
                $finishedRun->getRowsAffected(),
                $finishedRun->getMessage() ?? 'Error executing job'
            );
        }
    }
}

