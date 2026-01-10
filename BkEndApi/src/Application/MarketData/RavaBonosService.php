<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\RavaBonosClient;
use FinHub\Infrastructure\MarketData\RavaCedearsCache;

/**
 * Servicio Application para Bonos desde RAVA (root body/link/count/exectime).
 */
final class RavaBonosService
{
    private RavaBonosClient $client;
    private RavaCedearsCache $cache;
    private LoggerInterface $logger;
    private \DateTimeZone $tz;
    private int $marketOpenMinutes = 11 * 60;
    private int $marketCloseMinutes = 18 * 60;

    public function __construct(
        RavaBonosClient $client,
        RavaCedearsCache $cache,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->logger = $logger;
        $this->tz = new \DateTimeZone('America/Argentina/Buenos_Aires');
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function listBonos(): array
    {
        $now = new \DateTimeImmutable('now');
        $nowTs = $now->getTimestamp();
        $ttl = $this->resolveTtlSeconds($now);
        $cached = $this->cache->read();

        if ($cached !== null && $this->isFresh($cached, $ttl, $nowTs)) {
            return $this->buildResponse(
                (array) ($cached['data'] ?? []),
                true,
                false,
                (int) ($cached['fetched_at'] ?? $nowTs),
                $ttl,
                isset($cached['backoff_until']) ? (int) $cached['backoff_until'] : null,
                null
            );
        }
        if ($cached !== null && $this->isInBackoff($cached, $nowTs)) {
            return $this->buildResponse(
                (array) ($cached['data'] ?? []),
                true,
                true,
                (int) ($cached['fetched_at'] ?? $nowTs),
                $ttl,
                isset($cached['backoff_until']) ? (int) $cached['backoff_until'] : null,
                'backoff'
            );
        }

        try {
            $raw = $this->client->fetchBonosRaw();
            $items = $this->normalizeItems($raw['body']);
            $data = [
                'items' => $items,
                'meta' => [
                    'count' => isset($raw['count']) ? (int) $raw['count'] : count($items),
                    'link' => (string) ($raw['link'] ?? ''),
                    'exectime' => $raw['exectime'] ?? null,
                    'source' => 'rava',
                ],
            ];
            $this->cache->write($data, $nowTs, $ttl, null);
            return $this->buildResponse($data, false, false, $nowTs, $ttl, null, null);
        } catch (\Throwable $exception) {
            $this->logger->info('rava.bonos.fetch_failed', [
                'message' => $exception->getMessage(),
            ]);
            if ($cached !== null && isset($cached['data'])) {
                $backoffSeconds = $this->resolveBackoffSeconds($now);
                $backoffUntil = $nowTs + $backoffSeconds;
                $this->cache->write((array) $cached['data'], (int) ($cached['fetched_at'] ?? $nowTs), $ttl, $backoffUntil);

                return $this->buildResponse(
                    (array) $cached['data'],
                    true,
                    true,
                    (int) ($cached['fetched_at'] ?? $nowTs),
                    $ttl,
                    $backoffUntil,
                    $exception->getMessage()
                );
            }
            throw new \RuntimeException('No se pudo obtener Bonos desde RAVA', 502);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $body
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(array $body): array
    {
        $items = [];
        foreach ($body as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = $this->stringOrNull($row['simbolo'] ?? null);
            if ($symbol === null || $symbol === '') {
                continue;
            }
            $items[] = $this->normalizeItem($symbol, $row);
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeItem(string $symbol, array $row): array
    {
        $fecha = $this->stringOrNull($row['fecha'] ?? null);
        $hora = $this->stringOrNull($row['hora'] ?? null);
        $snapshotAt = $this->buildSnapshotAt($fecha, $hora);
        $especie = $this->stringOrNull($row['especie'] ?? null);

        return [
            'symbol' => $symbol,
            'especie' => $especie,
            'plazo' => $this->intOrNull($row['plazo'] ?? null),
            'ultimo' => $this->floatOrNull($row['ultimo'] ?? null),
            'variacion' => $this->floatOrNull($row['variacion'] ?? null),
            'var_mtd' => $this->floatOrNull($row['varMTD'] ?? null),
            'var_ytd' => $this->floatOrNull($row['varYTD'] ?? null),
            'anterior' => $this->floatOrNull($row['anterior'] ?? null),
            'apertura' => $this->floatOrNull($row['apertura'] ?? null),
            'minimo' => $this->floatOrNull($row['minimo'] ?? null),
            'maximo' => $this->floatOrNull($row['maximo'] ?? null),
            'precio_compra' => $this->floatOrNull($row['preciocompra'] ?? null),
            'precio_venta' => $this->floatOrNull($row['precioventa'] ?? null),
            'cantidad_compra' => $this->intOrNull($row['cantcompra'] ?? null),
            'cantidad_venta' => $this->intOrNull($row['cantventa'] ?? null),
            'volumen_nominal' => $this->floatOrNull($row['volnominal'] ?? null),
            'volumen_efectivo' => $this->floatOrNull($row['volefectivo'] ?? null),
            'operaciones' => $this->intOrNull($row['operaciones'] ?? null),
            'nombre' => $this->stringOrNull($row['nombre'] ?? null),
            'panel' => $this->stringOrNull($row['panel'] ?? null),
            'mercado' => $this->stringOrNull($row['mercado'] ?? null),
            'ratio' => $this->ratioToString($row['ratio'] ?? null),
            'fecha' => $fecha,
            'hora' => $hora,
            'as_of' => $snapshotAt,
            'currency' => $this->inferCurrency($especie),
            'provider' => 'rava',
            'asset_type' => 'BOND_AR',
            'mic' => 'XBUE',
        ];
    }

    private function inferCurrency(?string $especie): string
    {
        if ($especie === null) {
            return 'ARS';
        }
        $upper = strtoupper($especie);
        if (str_ends_with($upper, '-USD')) {
            return 'USD';
        }
        if (str_ends_with($upper, '-ARS')) {
            return 'ARS';
        }
        return 'ARS';
    }

    private function resolveTtlSeconds(\DateTimeImmutable $now): int
    {
        return $this->isMarketOpen($now) ? 90 : 1800;
    }

    private function resolveBackoffSeconds(\DateTimeImmutable $now): int
    {
        return $this->isMarketOpen($now) ? 60 : 300;
    }

    private function isMarketOpen(\DateTimeImmutable $now): bool
    {
        $local = $now->setTimezone($this->tz);
        $day = (int) $local->format('N');
        if ($day >= 6) {
            return false;
        }
        $minutes = ((int) $local->format('G')) * 60 + (int) $local->format('i');
        return $minutes >= $this->marketOpenMinutes && $minutes <= $this->marketCloseMinutes;
    }

    /**
     * @param array{fetched_at?:int,ttl?:int,backoff_until?:int,data?:array<string,mixed>} $cached
     */
    private function isFresh(array $cached, int $ttl, int $nowTs): bool
    {
        if (!isset($cached['fetched_at'])) {
            return false;
        }
        $fetchedAt = (int) $cached['fetched_at'];
        return $fetchedAt > 0 && ($nowTs - $fetchedAt) <= $ttl;
    }

    /**
     * @param array{backoff_until?:int} $cached
     */
    private function isInBackoff(array $cached, int $nowTs): bool
    {
        if (!isset($cached['backoff_until'])) {
            return false;
        }
        return (int) $cached['backoff_until'] > $nowTs;
    }

    private function buildSnapshotAt(?string $date, ?string $time): ?string
    {
        if ($date === null || $time === null) {
            return null;
        }
        $candidate = sprintf('%sT%s', $date, $time);
        try {
            $dt = new \DateTimeImmutable($candidate, $this->tz);
            return $dt->format(DATE_ATOM);
        } catch (\Throwable $e) {
            $this->logger->info('rava.bonos.datetime_parse_failed', [
                'value' => $candidate,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }
        $normalized = $raw;
        $hasComma = str_contains($raw, ',');
        $hasDot = str_contains($raw, '.');
        if ($hasComma && $hasDot) {
            $normalized = str_replace('.', '', $raw);
            $normalized = str_replace(',', '.', $normalized);
        } elseif ($hasComma) {
            $normalized = str_replace(',', '.', $raw);
        }
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function intOrNull($value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric((string) $value)) {
            return (int) $value;
        }
        return null;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function ratioToString($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }
        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        return null;
    }

    private function buildResponse(
        array $data,
        bool $cached,
        bool $stale,
        int $fetchedAt,
        int $ttl,
        ?int $backoffUntil,
        ?string $error
    ): array {
        $items = $data['items'] ?? [];
        $meta = $data['meta'] ?? [];
        $meta['count'] = $meta['count'] ?? count($items);
        $meta['cached'] = $cached;
        $meta['stale'] = $stale;
        $meta['ttl_seconds'] = $ttl;
        $meta['fetched_at'] = $this->formatIso($fetchedAt);
        $meta['backoff_until'] = $backoffUntil !== null && $backoffUntil > 0 ? $this->formatIso($backoffUntil) : null;
        $meta['as_of'] = $meta['as_of'] ?? $this->maxAsOf($items);
        $meta['source'] = 'rava';
        if ($error !== null) {
            $meta['error'] = $error;
        }

        return [
            'items' => $items,
            'meta' => $meta,
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function maxAsOf(array $items): ?string
    {
        $timestamps = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $asOf = $item['as_of'] ?? null;
            if (is_string($asOf) && trim($asOf) !== '') {
                $ts = strtotime($asOf);
                if ($ts !== false) {
                    $timestamps[] = $ts;
                }
            }
        }
        if (empty($timestamps)) {
            return null;
        }
        return $this->formatIso(max($timestamps));
    }

    private function formatIso(int $timestamp): string
    {
        $dt = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($this->tz);
        return $dt->format(DATE_ATOM);
    }
}
