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
        $results = [
            'started_at' => date('c', (int) $startedAt),
            'finished_at' => null,
            'total_symbols' => count($symbols),
            'ok' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($symbols as $symbol) {
            $snapshot = $this->priceService->fetchSnapshot($symbol);
            if (!isset($snapshot['provider']) || $snapshot['provider'] === null || $snapshot['provider'] === '') {
                $snapshot['provider'] = $snapshot['source'] ?? 'unknown';
            }
            // Validar que el payload contenga precio antes de persistir
            $price = $this->extractPrice($snapshot['payload'] ?? []);
            if ($price === null) {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => 'Precio no disponible en payload'];
                continue;
            }
            $stored = $this->repository->storeSnapshot($snapshot);
            if ($stored['success']) {
                $results['ok']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => $stored['reason'] ?? 'unknown'];
            }
        }

        $results['finished_at'] = date('c');
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
            $price = $this->extractPrice($row['payload']);
            if ($price === null) {
                continue;
            }
            $asOfIso = (new \DateTimeImmutable((string) $row['as_of']))->format(\DateTimeInterface::ATOM);
            $points[] = ['t' => $asOfIso, 'price' => $price];
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
}
