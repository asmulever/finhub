<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\Etl\GetEtlStatusUseCase;
use App\Application\Etl\RunEtlJobUseCase;
use App\Infrastructure\CronSecretValidator;
use App\Infrastructure\RequestContext;

class EtlController extends BaseController
{
    private const ORIGIN = 'etl-cron';

    public function __construct(
        private readonly RunEtlJobUseCase $jobUseCase,
        private readonly CronSecretValidator $secretValidator,
        private readonly GetEtlStatusUseCase $statusUseCase
    ) {
    }

    public function ingest(): void
    {
        if (!$this->hasValidSecret()) {
            return;
        }

        try {
            $payload = $this->jobUseCase->ingest($this->collectIngestParams());
        } catch (\InvalidArgumentException $e) {
            $this->logWarning(400, 'Invalid or missing source for ingest job', [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(400);
            echo json_encode(['error' => 'Invalid source']);
            return;
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'internal_error']);
            return;
        }

        $this->respondWithPayload($payload);
    }

    public function normalizePrices(): void
    {
        if (!$this->hasValidSecret()) {
            return;
        }

        try {
            $payload = $this->jobUseCase->normalizePrices($this->collectNormalizeParams());
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'internal_error']);
            return;
        }

        $this->respondWithPayload($payload);
    }

    public function calcIndicators(): void
    {
        if (!$this->hasValidSecret()) {
            return;
        }

        try {
            $payload = $this->jobUseCase->calcIndicators($this->collectIndicatorsParams());
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'internal_error']);
            return;
        }

        $this->respondWithPayload($payload);
    }

    public function calcSignals(): void
    {
        if (!$this->hasValidSecret()) {
            return;
        }

        try {
            $payload = $this->jobUseCase->calcSignals($this->collectSignalsParams());
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'internal_error']);
            return;
        }

        $this->respondWithPayload($payload);
    }

    public function status(): void
    {
        if (!$this->hasValidSecret()) {
            return;
        }

        try {
            $payload = [
                'status' => 'OK',
                'jobs' => $this->statusUseCase->execute(),
            ];
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), [
                'route' => RequestContext::getRoute(),
                'origin' => self::ORIGIN,
            ]);
            http_response_code(500);
            echo json_encode(['error' => 'internal_error']);
            return;
        }

        $this->respondWithPayload($payload);
    }

    private function hasValidSecret(): bool
    {
        return $this->secretValidator->validate(RequestContext::getRoute(), $_GET, $_SERVER);
    }

    /**
     * @return array<string,mixed>
     */
    private function collectIngestParams(): array
    {
        return [
            'source' => $_GET['source'] ?? '',
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'days' => $_GET['days'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectNormalizeParams(): array
    {
        return [
            'from_date' => $_GET['from_date'] ?? null,
            'to_date' => $_GET['to_date'] ?? null,
            'days' => $_GET['days'] ?? null,
            'staging_retention_days' => $_GET['staging_retention_days'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectIndicatorsParams(): array
    {
        return [
            'days' => $_GET['days'] ?? null,
            'history_days' => $_GET['history_days'] ?? null,
            'instrument_limit' => $_GET['instrument_limit'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function collectSignalsParams(): array
    {
        return [
            'target_date' => $_GET['target_date'] ?? null,
            'instrument_limit' => $_GET['instrument_limit'] ?? null,
        ];
    }

    private function respondWithPayload(array $payload): void
    {
        $statusCode = ($payload['status'] ?? '') === 'OK' ? 200 : 500;
        http_response_code($statusCode);
        echo json_encode($payload);
    }
}
