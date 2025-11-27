<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\EtlIndicatorService;
use App\Application\EtlIngestService;
use App\Application\EtlJobRunner;
use App\Application\EtlJobResult;
use App\Application\EtlNormalizeService;
use App\Application\EtlSignalService;
use App\Domain\EtlRun;
use App\Infrastructure\Config;
use App\Infrastructure\RequestContext;

class EtlController extends BaseController
{
    public function __construct(
        private readonly EtlJobRunner $jobRunner,
        private readonly EtlIngestService $ingestService,
        private readonly EtlNormalizeService $normalizeService,
        private readonly EtlIndicatorService $indicatorService,
        private readonly EtlSignalService $signalService
    ) {
    }

    private function getCronSecretFromEnv(): ?string
    {
        $primary = Config::get('CRON_SECRET');
        if (is_string($primary) && $primary !== '') {
            return $primary;
        }

        $legacy = Config::get('cronapikey');
        return is_string($legacy) && $legacy !== '' ? $legacy : null;
    }

    private function validateCronSecret(): bool
    {
        $expected = $this->getCronSecretFromEnv();
        if ($expected === null || $expected === '') {
            $this->logger()->warning('CRON secret is not configured', [
                'origin' => 'etl-cron',
                'route' => RequestContext::getRoute(),
                'http_status' => 500,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'CRON secret not configured']);
            return false;
        }

        $provided = null;
        if (isset($_GET['secret']) && is_string($_GET['secret'])) {
            $provided = $_GET['secret'];
        } elseif (isset($_SERVER['HTTP_X_CRON_KEY']) && is_string($_SERVER['HTTP_X_CRON_KEY'])) {
            $provided = $_SERVER['HTTP_X_CRON_KEY'];
        }

        if (!is_string($provided) || $provided === '' || !hash_equals($expected, $provided)) {
            $this->logWarning(403, 'Invalid CRON secret', [
                'route' => RequestContext::getRoute(),
                'origin' => 'etl-cron',
            ]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return false;
        }

        return true;
    }

    private function respondFromResult(EtlJobResult $result, array $extra = []): void
    {
        $payload = array_merge($result->toArray(), $extra);
        $statusCode = $payload['status'] === 'OK' ? 200 : 500;
        http_response_code($statusCode);
        echo json_encode($payload);
    }

    public function ingest(): void
    {
        if (!$this->validateCronSecret()) {
            return;
        }

        $source = isset($_GET['source']) && is_string($_GET['source']) ? strtoupper($_GET['source']) : '';
        if ($source !== 'RAVA' && $source !== 'FINHUB') {
            $this->logWarning(400, 'Invalid or missing source for ingest job', [
                'route' => RequestContext::getRoute(),
                'origin' => 'etl-cron',
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid source']);
            return;
        }

        $fromDate = isset($_GET['from_date']) && is_string($_GET['from_date']) ? $_GET['from_date'] : null;
        $toDate = isset($_GET['to_date']) && is_string($_GET['to_date']) ? $_GET['to_date'] : null;
        $defaultDays = isset($_GET['days']) ? (int)$_GET['days'] : 7;

        $jobName = 'INGEST_' . $source;
        $stats = null;
        $result = $this->jobRunner->runJob($jobName, function (EtlRun $run) use ($source, $fromDate, $toDate, $defaultDays, &$stats): int {
            $stats = $this->ingestService->ingest($source, $fromDate, $toDate, max(1, $defaultDays));
            return (int)($stats['rows'] ?? 0);
        });

        $extra = [
            'rows_affected' => $result->toArray()['rows_affected'],
            'details' => $stats,
            'message' => 'Ingest completed',
        ];

        $this->respondFromResult($result, $extra);
    }

    public function normalizePrices(): void
    {
        if (!$this->validateCronSecret()) {
            return;
        }

        $jobName = 'NORMALIZE_PRICES';
        $fromDate = isset($_GET['from_date']) && is_string($_GET['from_date']) ? $_GET['from_date'] : null;
        $toDate = isset($_GET['to_date']) && is_string($_GET['to_date']) ? $_GET['to_date'] : null;
        $defaultDays = isset($_GET['days']) ? (int)$_GET['days'] : 7;
        $retentionDays = isset($_GET['staging_retention_days']) ? (int)$_GET['staging_retention_days'] : 60;

        $stats = null;
        $result = $this->jobRunner->runJob($jobName, function (EtlRun $run) use ($fromDate, $toDate, $defaultDays, $retentionDays, &$stats): int {
            $stats = $this->normalizeService->normalize(
                $fromDate,
                $toDate,
                max(1, $defaultDays),
                max(1, $retentionDays)
            );
            return (int)(($stats['inserted'] ?? 0) + ($stats['updated'] ?? 0));
        });

        $extra = [
            'rows_affected' => $result->toArray()['rows_affected'],
            'details' => $stats,
            'message' => 'Normalize completed',
        ];

        $this->respondFromResult($result, $extra);
    }

    public function calcIndicators(): void
    {
        if (!$this->validateCronSecret()) {
            return;
        }

        $jobName = 'CALC_INDICATORS';
        $days = isset($_GET['days']) ? (int)$_GET['days'] : 60;
        $historyDays = isset($_GET['history_days']) ? (int)$_GET['history_days'] : 260;
        $instrumentLimit = isset($_GET['instrument_limit']) ? (int)$_GET['instrument_limit'] : 100;

        $stats = null;
        $result = $this->jobRunner->runJob($jobName, function (EtlRun $run) use ($days, $historyDays, $instrumentLimit, &$stats): int {
            $stats = $this->indicatorService->recalcIndicators(
                max(1, $days),
                max(1, $historyDays),
                max(1, $instrumentLimit)
            );
            return (int)($stats['rows_updated'] ?? 0);
        });

        $extra = [
            'rows_affected' => $result->toArray()['rows_affected'],
            'details' => $stats,
            'message' => 'Indicators recalculated',
        ];

        $this->respondFromResult($result, $extra);
    }

    public function calcSignals(): void
    {
        if (!$this->validateCronSecret()) {
            return;
        }

        $jobName = 'CALC_SIGNALS';
        $targetDate = isset($_GET['target_date']) && is_string($_GET['target_date']) ? $_GET['target_date'] : null;
        $instrumentLimit = isset($_GET['instrument_limit']) ? (int)$_GET['instrument_limit'] : 100;

        $stats = null;
        $result = $this->jobRunner->runJob($jobName, function (EtlRun $run) use ($targetDate, $instrumentLimit, &$stats): int {
            $stats = $this->signalService->recalcSignals($targetDate, max(1, $instrumentLimit));
            return (int)($stats['signals_updated'] ?? 0);
        });

        $extra = [
            'rows_affected' => $result->toArray()['rows_affected'],
            'details' => $stats,
            'message' => 'Signals recalculated',
        ];

        $this->respondFromResult($result, $extra);
    }
}
