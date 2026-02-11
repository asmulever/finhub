<?php
declare(strict_types=1);

namespace FinHub\Application\R2Lite;

use FinHub\Application\DataLake\PriceSnapshotRepositoryInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Cache\CacheInterface;
use FinHub\Infrastructure\R2Lite\R2LiteAuditLogger;

/**
 * Orquestador R2Lite: garantiza series históricas (1 año) consultando proveedores y almacenando en el Data Lake.
 */
final class R2LiteService
{
    private PriceSnapshotRepositoryInterface $repo;
    /** @var array<string,ProviderInterface> */
    private array $providers;
    private LoggerInterface $logger;
    private CacheInterface $cache;
    private ?R2LiteAuditLogger $audit = null;
    private const CACHE_TTL_FETCH = 300; // 5 minutos para evitar refetch inmediato

    /**
     * @param array<string,ProviderInterface> $providers Asociativo por nombre (rava, twelvedata, alphavantage, etc.)
     */
    public function __construct(
        PriceSnapshotRepositoryInterface $repo,
        array $providers,
        LoggerInterface $logger,
        CacheInterface $cache,
        ?R2LiteAuditLogger $audit = null
    ) {
        $this->repo = $repo;
        $this->providers = $providers;
        $this->logger = $logger;
        $this->cache = $cache;
        $this->audit = $audit;
    }

    /**
     * Garantiza datos diarios de 1 año para cada símbolo y categoría.
     *
     * @param array<int,string> $symbols
     * @return array<string,mixed> estado de ingesta
     */
    public function ensureSeries(array $symbols, string $category): array
    {
        $this->repo->ensureTables();
        $symbols = $this->normalizeSymbols($symbols);
        $from = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-365 days');
        $to = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $attempts = [];
        foreach ($symbols as $symbol) {
            $attempts[$symbol] = $this->ingestSymbol($symbol, $category, $from, $to);
        }

        return [
            'ready' => true,
            'attempts' => $attempts,
        ];
    }

    /**
     * Ingresa snapshots usando un proveedor concreto y devuelve los snapshots normalizados.
     *
     * @return array<int,array<string,mixed>>
     */
    public function fetchAndStore(string $providerName, array $symbols, string $category): array
    {
        $provider = $this->providers[$providerName] ?? null;
        if ($provider === null) {
            throw new \RuntimeException(sprintf('Proveedor no soportado: %s', $providerName), 400);
        }
        $this->repo->ensureTables();
        $symbols = $this->normalizeSymbols($symbols);
        $from = (new \DateTimeImmutable('today', new \DateTimeZone('UTC')))->modify('-365 days');
        $to = new \DateTimeImmutable('today', new \DateTimeZone('UTC'));

        $this->audit?->write([
            'event' => 'request',
            'provider' => $providerName,
            'category' => $category,
            'symbols' => $symbols,
            'from' => $from->format('Y-m-d'),
            'to' => $to->format('Y-m-d'),
        ]);

        $allSnapshots = [];
        foreach ($symbols as $symbol) {
            try {
                $candles = $provider->fetchDaily($symbol, $from, $to, $category);
            } catch (\Throwable $e) {
                $this->audit?->write([
                    'event' => 'error',
                    'provider' => $providerName,
                    'category' => $category,
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ]);
                throw $e;
            }

            foreach ($candles as $candle) {
                $snapshot = $this->normalizeSnapshot($candle, $symbol, $category, $provider->name());
                $this->repo->storeSnapshot([
                    'symbol' => $snapshot['symbol'],
                    'category' => $snapshot['category'],
                    'provider' => $snapshot['provider'],
                    'as_of' => $snapshot['as_of'],
                    'payload' => $snapshot,
                    'http_status' => null,
                    'error_code' => null,
                    'error_msg' => null,
                ]);
                $allSnapshots[] = $snapshot;
            }
            $first = $candles[0] ?? null;
            $this->audit?->write([
                'event' => 'result',
                'provider' => $providerName,
                'category' => $category,
                'symbol' => $symbol,
                'count' => count($candles),
                'as_of' => $first['as_of'] ?? $first['date'] ?? null,
                'close' => $first['close'] ?? null,
                'currency' => $first['currency'] ?? null,
            ]);
        }
        return $allSnapshots;
    }

    public function purgeExpired(): int
    {
        return $this->repo->purgeOlderThan(new \DateTimeImmutable('-365 days'));
    }

    /**
     * @return array<string,mixed>
     */
    private function ingestSymbol(string $symbol, string $category, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $missing = $this->missingDates($symbol, $from, $to);
        if (empty($missing)) {
            return ['status' => 'cached', 'missing' => []];
        }

        $cacheKey = sprintf('r2lite:lock:%s:%s:%s', $symbol, $category, $from->format('Ymd'));
        $lock = $this->cache->increment($cacheKey, self::CACHE_TTL_FETCH);
        if ($lock > 1) {
            return ['status' => 'skipped_locked', 'missing' => []];
        }

        foreach ($this->providers as $provider) {
            try {
                $candles = $provider->fetchDaily($symbol, $from, $to, $category);
                if (empty($candles)) {
                    continue;
                }
                foreach ($candles as $candle) {
                    $snapshot = $this->normalizeSnapshot($candle, $symbol, $category, $provider->name());
                    $this->repo->storeSnapshot([
                        'symbol' => $snapshot['symbol'],
                        'category' => $snapshot['category'],
                        'provider' => $snapshot['provider'],
                        'as_of' => $snapshot['as_of'],
                        'payload' => $snapshot,
                        'http_status' => null,
                        'error_code' => null,
                        'error_msg' => null,
                    ]);
                }
                return ['status' => 'ingested', 'provider' => $provider->name(), 'missing' => $missing];
            } catch (\Throwable $e) {
                $this->logger->warning('r2lite.provider.failed', [
                    'symbol' => $symbol,
                    'provider' => $provider->name(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['status' => 'failed', 'missing' => $missing];
    }

    /**
     * @return array<int,string> fechas faltantes (Y-m-d)
     */
    private function missingDates(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $series = $this->repo->fetchSeries($symbol, $from);
        $have = [];
        foreach ($series as $row) {
            $date = substr((string) ($row['as_of'] ?? ''), 0, 10);
            if ($date !== '') {
                $have[$date] = true;
            }
        }
        $missing = [];
        for ($d = $from; $d <= $to; $d = $d->modify('+1 day')) {
            $key = $d->format('Y-m-d');
            if (!isset($have[$key])) {
                $missing[] = $key;
            }
        }
        return $missing;
    }

    private function normalizeSymbols(array $symbols): array
    {
        $out = [];
        foreach ($symbols as $s) {
            $v = strtoupper(trim((string) $s));
            if ($v !== '') {
                $out[$v] = true;
            }
        }
        return array_keys($out);
    }

    private function parseDate(?string $value): \DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Throwable) {
            return new \DateTimeImmutable('today', new \DateTimeZone('UTC'));
        }
    }

    /**
     * @param array<string,mixed> $candle
     * @return array<string,mixed>
     */
    private function normalizeSnapshot(array $candle, string $symbol, string $category, string $provider): array
    {
        $asOf = $candle['as_of'] ?? $candle['date'] ?? $candle['timestamp'] ?? null;
        $asOfDt = $this->parseDate(is_string($asOf) ? $asOf : null);
        return [
            'symbol' => strtoupper($symbol),
            'category' => $category,
            'as_of' => $asOfDt,
            'open' => isset($candle['open']) ? (float) $candle['open'] : null,
            'high' => isset($candle['high']) ? (float) $candle['high'] : null,
            'low' => isset($candle['low']) ? (float) $candle['low'] : null,
            'close' => isset($candle['close']) ? (float) $candle['close'] : null,
            'volume' => isset($candle['volume']) ? (float) $candle['volume'] : null,
            'currency' => $candle['currency'] ?? ($category === 'ACCIONES_AR' ? 'ARS' : 'USD'),
            'provider' => $provider,
        ];
    }
}
