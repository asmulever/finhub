<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

use FinHub\Application\MarketData\PriceService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Casos de uso de Data Lake: ingesta, lectura de último precio y series.
 */
final class DataLakeService
{
    private PriceSnapshotRepositoryInterface $repository;
    private PriceService $priceService;
    private LoggerInterface $logger;

    public function __construct(
        PriceSnapshotRepositoryInterface $repository,
        PriceService $priceService,
        LoggerInterface $logger
    ) {
        $this->repository = $repository;
        $this->priceService = $priceService;
        $this->logger = $logger;
    }

    public function collect(array $symbols): array
    {
        $this->repository->ensureTables();
        $startedAt = microtime(true);
        $total = count($symbols);
        $this->logger->info('datalake.collect.start', [
            'total_symbols' => $total,
        ]);
        $results = [
            'started_at' => date('c', (int) $startedAt),
            'finished_at' => null,
            'total_symbols' => $total,
            'ok' => 0,
            'failed' => 0,
            'errors' => [],
            'steps' => [],
        ];

        $this->appendStep($results['steps'], '', 'init', 'running', 'Iniciando proceso de ingesta', ['current' => 0, 'total' => $total]);

        foreach ($symbols as $index => $symbol) {
            $progress = ['current' => $index + 1, 'total' => $total];
            $this->appendStep($results['steps'], $symbol, 'start', 'running', 'Iniciando ingesta de símbolo', $progress);

            try {
                $snapshot = $this->priceService->fetchSnapshot($symbol);
                if (!isset($snapshot['provider']) || $snapshot['provider'] === null || $snapshot['provider'] === '') {
                    $snapshot['provider'] = $snapshot['source'] ?? 'unknown';
                }
                $this->appendStep($results['steps'], $symbol, 'fetch', 'ok', 'Snapshot obtenido del proveedor', $progress);
            } catch (\Throwable $e) {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => $e->getMessage()];
                $this->appendStep($results['steps'], $symbol, 'fetch', 'error', $e->getMessage(), $progress);
                $this->logger->info('datalake.collect.fetch_failed', [
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ]);
                continue;
            }
            // Validar que el payload contenga precio antes de persistir
            $price = $this->extractPrice($snapshot['payload'] ?? []);
            if ($price === null) {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => 'Precio no disponible en payload'];
                $this->appendStep($results['steps'], $symbol, 'validate', 'error', 'Precio no disponible en payload', $progress);
                continue;
            }

            $stored = $this->repository->storeSnapshot($snapshot);
            if ($stored['success']) {
                $results['ok']++;
                $this->appendStep($results['steps'], $symbol, 'store', 'ok', 'Snapshot almacenado', $progress);
            } else {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => $stored['reason'] ?? 'unknown'];
                $this->appendStep($results['steps'], $symbol, 'store', 'error', $stored['reason'] ?? 'Error al almacenar', $progress);
                $this->logger->info('datalake.collect.store_failed', [
                    'symbol' => $symbol,
                    'message' => $stored['reason'] ?? 'Error al almacenar snapshot',
                ]);
            }
        }

        $results['finished_at'] = date('c');
        $results['duration_seconds'] = round(microtime(true) - $startedAt, 3);
        $this->appendStep($results['steps'], '', 'complete', 'ok', 'Proceso finalizado', ['current' => $results['ok'] + $results['failed'], 'total' => $total]);
        $errorSymbols = array_map(static fn ($e) => $e['symbol'] ?? '', $results['errors']);
        $results['summary'] = sprintf(
            'OK: %d | Fallidos: %d | Total: %d | Errores: %s',
            $results['ok'],
            $results['failed'],
            $results['total_symbols'],
            empty($errorSymbols) ? 'ninguno' : implode(',', array_filter($errorSymbols))
        );
        $this->logger->info('datalake.collect.done', [
            'ok' => $results['ok'],
            'failed' => $results['failed'],
            'total' => $results['total_symbols'],
            'duration' => $results['duration_seconds'],
        ]);
        return $results;
    }

    public function latestQuote(string $symbol): array
    {
        $this->repository->ensureTables();
        $snapshot = $this->repository->fetchLatest($symbol);
        if ($snapshot === null) {
            throw new \RuntimeException('Precio no disponible en Data Lake', 404);
        }
        $quote = $this->normalizeSnapshotPayload($snapshot['payload'], $snapshot['symbol'], $snapshot['provider'], $snapshot['as_of']);
        if ($quote['close'] !== null) {
            return $quote;
        }
        // Fallback: buscar último snapshot con precio válido
        $series = array_reverse($this->repository->fetchSeries($symbol, null));
        foreach ($series as $row) {
            $price = $this->extractPrice($row['payload']);
            if ($price === null) {
                continue;
            }
            $quote['close'] = $price;
            $quote['asOf'] = $row['as_of'];
            break;
        }
        if ($quote['close'] === null) {
            throw new \RuntimeException('Precio no disponible en Data Lake', 404);
        }
        return $quote;
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

    private function extractPrice(array $payload): ?float
    {
        if (isset($payload[0]) && is_array($payload[0])) {
            $payload = $payload[0];
        }
        $candidates = [
            $payload['close'] ?? null,
            $payload['price'] ?? null,
            $payload['c'] ?? null,
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

    private function normalizeSnapshotPayload(array $payload, string $symbol, string $provider, string $asOf): array
    {
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
