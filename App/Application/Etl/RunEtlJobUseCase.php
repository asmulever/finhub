<?php

declare(strict_types=1);

namespace App\Application\Etl;

use App\Application\EtlIndicatorService;
use App\Application\EtlIngestService;
use App\Application\EtlJobRunner;
use App\Application\EtlNormalizeService;
use App\Application\EtlSignalService;
use App\Domain\EtlRun;
use App\Application\EtlJobResult;
use InvalidArgumentException;

final class RunEtlJobUseCase
{
    public function __construct(
        private readonly EtlJobRunner $jobRunner,
        private readonly EtlIngestService $ingestService,
        private readonly EtlNormalizeService $normalizeService,
        private readonly EtlIndicatorService $indicatorService,
        private readonly EtlSignalService $signalService
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    public function ingest(array $input): array
    {
        $source = $this->normalizeSource($input['source'] ?? '');
        $fromDate = $this->normalizeString($input['from_date'] ?? null);
        $toDate = $this->normalizeString($input['to_date'] ?? null);
        $days = $this->normalizePositiveInt($input['days'] ?? 7);

        $stats = null;
        $jobName = 'INGEST_' . $source;

        $result = $this->jobRunner->runJob($jobName, function (EtlRun $run) use (&$stats, $source, $fromDate, $toDate, $days): int {
            $stats = $this->ingestService->ingest($source, $fromDate, $toDate, $days);
            return (int)($stats['rows'] ?? 0);
        });

        return $this->buildPayload($result, [
            'details' => $stats,
            'message' => 'Ingest completed',
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function normalizePrices(array $input): array
    {
        $fromDate = $this->normalizeString($input['from_date'] ?? null);
        $toDate = $this->normalizeString($input['to_date'] ?? null);
        $days = $this->normalizePositiveInt($input['days'] ?? 7);
        $retention = $this->normalizePositiveInt($input['staging_retention_days'] ?? 60);

        $stats = null;
        $result = $this->jobRunner->runJob('NORMALIZE_PRICES', function (EtlRun $run) use (&$stats, $fromDate, $toDate, $days, $retention): int {
            $stats = $this->normalizeService->normalize($fromDate, $toDate, $days, $retention);
            return (int)(($stats['inserted'] ?? 0) + ($stats['updated'] ?? 0));
        });

        return $this->buildPayload($result, [
            'details' => $stats,
            'message' => 'Normalize completed',
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function calcIndicators(array $input): array
    {
        $days = $this->normalizePositiveInt($input['days'] ?? 60);
        $historyDays = $this->normalizePositiveInt($input['history_days'] ?? 260);
        $instrumentLimit = $this->normalizePositiveInt($input['instrument_limit'] ?? 100);

        $stats = null;
        $result = $this->jobRunner->runJob('CALC_INDICATORS', function (EtlRun $run) use (&$stats, $days, $historyDays, $instrumentLimit): int {
            $stats = $this->indicatorService->recalcIndicators($days, $historyDays, $instrumentLimit);
            return (int)($stats['rows_updated'] ?? 0);
        });

        return $this->buildPayload($result, [
            'details' => $stats,
            'message' => 'Indicators recalculated',
        ]);
    }

    /**
     * @param array<string,mixed> $input
     */
    public function calcSignals(array $input): array
    {
        $targetDate = $this->normalizeString($input['target_date'] ?? null);
        $instrumentLimit = $this->normalizePositiveInt($input['instrument_limit'] ?? 100);

        $stats = null;
        $result = $this->jobRunner->runJob('CALC_SIGNALS', function (EtlRun $run) use (&$stats, $targetDate, $instrumentLimit): int {
            $stats = $this->signalService->recalcSignals($targetDate, $instrumentLimit);
            return (int)($stats['signals_updated'] ?? 0);
        });

        return $this->buildPayload($result, [
            'details' => $stats,
            'message' => 'Signals recalculated',
        ]);
    }

    private function normalizeSource(mixed $value): string
    {
        $source = strtoupper(trim((string)$value));
        if ($source !== 'RAVA' && $source !== 'FINNHUB') {
            throw new InvalidArgumentException('Invalid source');
        }

        return $source;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePositiveInt(mixed $value, int $fallback): int
    {
        if (is_numeric($value)) {
            return max(1, (int)$value);
        }

        return $fallback;
    }

    /**
     * @param array<string,mixed> $extra
     */
    private function buildPayload(EtlJobResult $result, array $extra): array
    {
        return array_merge($result->toArray(), $extra);
    }
}
