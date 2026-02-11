<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Application\Cache\CacheInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Servicio de Application para normalizar vistas públicas de RAVA.
 */
final class RavaViewsService
{
    private const TTL_CATALOG = 120; // segundos
    private const TTL_DOLARES = 60;  // segundos

    private RavaViewsClientInterface $client;
    private LoggerInterface $logger;
    private CacheInterface $cache;

    public function __construct(RavaViewsClientInterface $client, LoggerInterface $logger, CacheInterface $cache)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->cache = $cache;
    }

    /**
     * Devuelve el catálogo combinado de RAVA (acciones, cedears, bonos, mercados globales).
     *
     * @return array<string,mixed>
     */
    public function fetchCatalog(): array
    {
        $cached = $this->cache->get('rava:catalog', null);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $items = [];
            $items = array_merge($items, $this->normalizeAcciones($this->client->fetchAcciones()));
            $items = array_merge($items, $this->normalizeBody('cedears', $this->client->fetchCedears()));
            $items = array_merge($items, $this->normalizeBody('bonos', $this->client->fetchBonos()));
            $items = array_merge($items, $this->normalizeMercadosGlobales($this->client->fetchMercadosGlobales()));

            $counts = $this->countByCategory($items);
            $result = [
                'items' => $items,
                'count' => count($items),
                'counts' => $counts,
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
            $this->cache->set('rava:catalog', $result, self::TTL_CATALOG);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->info('rava.catalog.fetch_failed', [
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo obtener el catálogo desde RAVA', 502, $e);
        }
    }

    /**
     * Devuelve la vista de dólares de RAVA.
     *
     * @return array<string,mixed>
     */
    public function fetchDolares(): array
    {
        $cached = $this->cache->get('rava:dolares', null);
        if (is_array($cached)) {
            return $cached;
        }

        try {
            $raw = $this->client->fetchDolares();
            $items = [];
            if (isset($raw['body']) && is_array($raw['body'])) {
                foreach ($raw['body'] as $row) {
                    $normalized = $this->normalizeItem((array) $row, 'dolares', null);
                    if ($normalized !== null) {
                        $items[] = $normalized;
                    }
                }
            }

            $result = [
                'items' => $items,
                'count' => count($items),
                'fetched_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ];
            $this->cache->set('rava:dolares', $result, self::TTL_DOLARES);
            return $result;
        } catch (\Throwable $e) {
            $this->logger->info('rava.dolares.fetch_failed', [
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo obtener la vista de dólares desde RAVA', 502, $e);
        }
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function normalizeAcciones(array $raw): array
    {
        $items = [];
        foreach ($raw as $segment => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $normalized = $this->normalizeItem((array) $row, 'acciones_argentinas', (string) $segment);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function normalizeMercadosGlobales(array $raw): array
    {
        $items = [];
        foreach ($raw as $segment => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $normalized = $this->normalizeItem((array) $row, 'mercados_globales', (string) $segment);
                if ($normalized !== null) {
                    $items[] = $normalized;
                }
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function normalizeBody(string $category, array $raw): array
    {
        $items = [];
        $rows = $raw['body'] ?? null;
        if (!is_array($rows)) {
            return $items;
        }
        foreach ($rows as $row) {
            $normalized = $this->normalizeItem((array) $row, $category, null);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }
        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>|null
     */
    private function normalizeItem(array $row, string $category, ?string $segment): ?array
    {
        $especie = $this->stringOrNull($row['especie'] ?? null);
        $simbolo = $this->stringOrNull($row['simbolo'] ?? null);
        $ticker = $especie !== null && $especie !== '' ? $especie : $simbolo;
        if ($ticker === null || $ticker === '') {
            return null;
        }

        $fecha = $this->stringOrNull($row['fecha'] ?? null);
        $hora = $this->stringOrNull($row['hora'] ?? null);

        return [
            'especie' => strtoupper($ticker),
            'symbol' => $simbolo !== null ? strtoupper($simbolo) : null,
            'nombre' => $this->stringOrNull($row['nombre'] ?? null),
            'category' => $category,
            'segment' => $segment,
            'panel' => $this->stringOrNull($row['panel'] ?? null),
            'mercado' => $this->stringOrNull($row['mercado'] ?? null),
            'ultimo' => $this->floatOrNull($row['ultimo'] ?? null),
            'variacion' => $this->floatOrNull($row['variacion'] ?? null),
            'var_mtd' => $this->floatOrNull($row['varMTD'] ?? $row['var_mtd'] ?? null),
            'var_ytd' => $this->floatOrNull($row['varYTD'] ?? $row['var_ytd'] ?? null),
            'as_of' => $this->buildAsOf($fecha, $hora),
            'descripcion' => $this->stringOrNull($row['descripcion'] ?? null),
            'source' => 'rava',
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array<string,int>
     */
    private function countByCategory(array $items): array
    {
        $counts = [
            'acciones_argentinas' => 0,
            'cedears' => 0,
            'bonos' => 0,
            'mercados_globales' => 0,
        ];
        foreach ($items as $item) {
            $category = $item['category'] ?? null;
            if (is_string($category) && isset($counts[$category])) {
                $counts[$category] += 1;
            }
        }
        return $counts;
    }

    private function buildAsOf(?string $fecha, ?string $hora): ?string
    {
        $fecha = $fecha !== null ? trim($fecha) : '';
        if ($fecha === '') {
            return null;
        }
        $hora = $hora !== null ? trim($hora) : '';
        if ($hora !== '') {
            if (preg_match('/^\d{2}:\d{2}$/', $hora) === 1) {
                $hora .= ':00';
            }
            return $fecha . ' ' . $hora;
        }
        return $fecha;
    }

    private function floatOrNull(mixed $value): ?float
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

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
