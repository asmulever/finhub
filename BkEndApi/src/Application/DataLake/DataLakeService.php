<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\R2Lite\R2LiteService;

/**
 * Casos de uso de Data Lake: ingesta, lectura de último precio y series.
 */
final class DataLakeService
{
    private PriceSnapshotRepositoryInterface $repository;
    private LoggerInterface $logger;
    private int $batchSize;
    private R2LiteService $r2lite;

    public function __construct(
        PriceSnapshotRepositoryInterface $repository,
        LoggerInterface $logger,
        int $batchSize = 10,
        ?R2LiteService $r2lite = null
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
        $this->batchSize = $batchSize > 0 ? $batchSize : 10;
        $this->r2lite = $r2lite ?? throw new \RuntimeException('R2LiteService requerido');
    }

    public function collect(array $symbols): array
    {
        // Asumir categoría por símbolo? default CEDEAR/ACCIONES_AR requiere mapping externo.
        $result = $this->r2lite->ensureSeries($symbols, 'ACCIONES_AR');
        return $result;
    }

    public function latestQuote(string $symbol): array
    {
        $this->repository->ensureTables();
        $snapshot = $this->repository->fetchLatest($symbol);
        if ($snapshot !== null) {
            $provider = (string) ($snapshot['provider'] ?? '');
            $quote = $this->normalizeSnapshotPayload($snapshot['payload'], $snapshot['symbol'], $provider, $snapshot['as_of']);
            $currency = $quote['currency'] ?? null;
            if ($quote['close'] !== null && $provider !== 'analysis' && $currency !== null && $currency !== '') {
                return $quote;
            }
        }

        // Fallback: buscar último snapshot válido (no analysis y con currency)
        $series = array_reverse($this->repository->fetchSeries($symbol, null));
        foreach ($series as $row) {
            $provider = (string) ($row['provider'] ?? '');
            if ($provider === 'analysis') {
                continue;
            }
            $normalized = $this->normalizeSnapshotPayload($row['payload'], $symbol, $provider, (string) $row['as_of']);
            $currency = $normalized['currency'] ?? null;
            $price = $normalized['close'];
            if ($price === null || $currency === null || $currency === '') {
                continue;
            }
            return $normalized;
        }

        throw new \RuntimeException('Precio no disponible en Data Lake', 404);
    }

    /**
     * Guarda un snapshot de análisis/indicadores en el Data Lake usando el mismo repositorio de snapshots.
     *
     * @param array<string,mixed> $payload
     */
    public function storeAnalysisSnapshot(string $symbol, array $payload): void
    {
        $this->repository->ensureTables();
        try {
            $this->repository->storeSnapshot([
                'symbol' => $symbol,
                'provider' => 'analysis',
                'as_of' => new \DateTimeImmutable(),
                'payload' => $payload,
                'http_status' => null,
                'error_code' => null,
                'error_msg' => null,
            ]);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.analysis.store_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function series(string $symbol, string $period): array
    {
        $this->repository->ensureTables();
        $since = $this->resolveSince($period);
        $rows = $this->repository->fetchSeries($symbol, $since);
        $points = [];
        foreach ($rows as $row) {
            $normalized = $this->normalizeSnapshotPayload($row['payload'], $symbol, (string) ($row['provider'] ?? 'unknown'), (string) $row['as_of']);
            $price = $normalized['close'];
            $open = $normalized['open'] ?? null;
            $high = $normalized['high'] ?? null;
            $low = $normalized['low'] ?? null;
            if ($price === null) {
                continue;
            }
            $asOfIso = (new \DateTimeImmutable((string) $row['as_of']))->format(\DateTimeInterface::ATOM);
            $points[] = [
                't' => $asOfIso,
                'price' => $price,
                'open' => $open !== null ? (float) $open : (float) $price,
                'high' => $high !== null ? (float) $high : (float) $price,
                'low' => $low !== null ? (float) $low : (float) $price,
                'close' => $price !== null ? (float) $price : null,
            ];
        }
        return [
            'symbol' => $symbol,
            'period' => $period,
            'points' => $points,
        ];
    }

    public function captureGroups(string $group = 'minute'): array
    {
        $this->repository->ensureTables();
        return $this->repository->fetchCaptureGroups($group);
    }

    public function capturesByBucket(string $bucket, string $group = 'minute', ?string $symbol = null): array
    {
        $this->repository->ensureTables();
        return $this->repository->fetchCaptures($bucket, $group, $symbol);
    }

    private function extractPrice(array $payload): ?float
    {
        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }
        $candidates = [
            $payload['close'] ?? null,
            $payload['price'] ?? null,
            $payload['c'] ?? null,
            $payload['ultimo'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    private function resolveSince(string $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        return match ($period) {
            '1m' => $now->modify('-1 month'),
            '3m' => $now->modify('-3 months'),
            '6m' => $now->modify('-6 months'),
            '1y' => $now->modify('-12 months'),
            default => null,
        };
    }

    private function normalizeSnapshotPayload(array|string $payload, string $symbol, string $provider, string $asOf): array
    {
        // Si viene como string JSON desde la base, decodificar.
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            if (is_array($decoded)) {
                $payload = $decoded;
            } else {
                $payload = [];
            }
        }

        // Si el payload es una lista (ej. EODHD devuelve array con un único elemento), tomar el primero.
        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }

        $close = $payload['close'] ?? $payload['price'] ?? $payload['c'] ?? null;
        $open = $payload['open'] ?? $payload['o'] ?? null;
        $high = $payload['high'] ?? $payload['h'] ?? null;
        $low = $payload['low'] ?? $payload['l'] ?? null;
        $previousClose = $payload['previous_close'] ?? $payload['previousClose'] ?? $payload['pc'] ?? null;
        $currency = $payload['currency'] ?? $payload['currency_code'] ?? null;
        $name = $payload['name'] ?? $payload['symbol'] ?? null;
        $asOfValue = $payload['as_of'] ?? $payload['datetime'] ?? $payload['timestamp'] ?? $payload['date'] ?? $asOf;

        return [
            'symbol' => $payload['symbol'] ?? $symbol,
            'name' => $name,
            'currency' => $currency,
            'close' => $close !== null ? (float) $close : null,
            'open' => $open !== null ? (float) $open : null,
            'high' => $high !== null ? (float) $high : null,
            'low' => $low !== null ? (float) $low : null,
            'previous_close' => $previousClose !== null ? (float) $previousClose : null,
            'asOf' => $asOfValue,
            'source' => $provider,
        ];
    }

    /**
     * Agrega un paso de trazabilidad de ingesta al resultado.
     */
    private function appendStep(array &$steps, string $symbol, string $stage, string $status, string $message, array $progress): void
    {
        $steps[] = [
            'at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'symbol' => $symbol,
            'stage' => $stage,
            'status' => $status,
            'message' => $message,
            'progress' => $progress,
        ];
    }

}
