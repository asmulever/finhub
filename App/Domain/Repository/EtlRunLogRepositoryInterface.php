<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\EtlRun;

interface EtlRunLogRepositoryInterface
{
    public function startRun(string $jobName): EtlRun;

    public function finishRun(EtlRun $run, string $status, int $rowsAffected, ?string $message = null): EtlRun;

    /**
     * @return EtlRun[]
     */
    public function findRecentByJob(string $jobName, int $limit = 50): array;
}
