<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

use FinHub\Application\MarketData\RavaAccionesService;
use FinHub\Application\MarketData\RavaBonosService;
use FinHub\Application\MarketData\RavaCedearsService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Servicio de catálogo de instrumentos (DataLake SERVING).
 * Construye/lee el índice desde fuentes RAVA unificadas.
 */
final class InstrumentCatalogService
{
    private RavaCedearsService $ravaCedearsService;
    private RavaAccionesService $ravaAccionesService;
    private RavaBonosService $ravaBonosService;
    private InstrumentCatalogRepositoryInterface $repository;
    private LoggerInterface $logger;

    public function __construct(
        RavaCedearsService $ravaCedearsService,
        RavaAccionesService $ravaAccionesService,
        RavaBonosService $ravaBonosService,
        InstrumentCatalogRepositoryInterface $repository,
        LoggerInterface $logger
    ) {
        $this->ravaCedearsService = $ravaCedearsService;
        $this->ravaAccionesService = $ravaAccionesService;
        $this->ravaBonosService = $ravaBonosService;
        $this->repository = $repository;
        $this->logger = $logger;
    }

    /**
     * Sincroniza catálogo desde RAVA (cedears/acciones/bonos) y persiste en DataLake.
     *
     * @return array<string,mixed>
     */
    public function syncFromRava(): array
    {
        try {
            $cedears = $this->ravaCedearsService->listCedears();
            $acciones = $this->ravaAccionesService->listAcciones();
            $bonos = $this->ravaBonosService->listBonos();
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.rava_fetch_failed', [
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo obtener listas RAVA para catálogo', 502, $e);
        }

        $map = [];
        $this->normalizeItems((array) ($cedears['items'] ?? []), 'CEDEAR', $map);
        $this->normalizeItems((array) ($acciones['items'] ?? []), 'ACCION_AR', $map);
        $this->normalizeItems((array) ($bonos['items'] ?? []), 'BONO', $map);

        $stored = 0;
        try {
            $stored = $this->repository->upsertMany(array_values($map));
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.persist_failed', [
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo persistir el catálogo en DataLake', 500, $e);
        }

        return [
            'stored' => $stored,
            'total' => count($map),
            'source' => 'rava',
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listCatalog(): array
    {
        try {
            return $this->repository->listAll();
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.list_failed', [
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo leer el catálogo de DataLake', 500, $e);
        }
    }

    public function find(string $symbol): ?array
    {
        try {
            return $this->repository->findBySymbol($symbol);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.find_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo leer el catálogo de DataLake', 500, $e);
        }
    }

    /**
     * Crea o actualiza un instrumento puntual.
     *
     * @param array<string,mixed> $payload
     */
    public function save(array $payload): array
    {
        $symbol = strtoupper(trim((string) ($payload['symbol'] ?? '')));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $record = $this->normalizeSingle($payload);
        try {
            $ok = $this->repository->upsertOne($record);
            if (!$ok) {
                throw new \RuntimeException('No se pudo guardar el instrumento', 500);
            }
            return $this->find($symbol) ?? $record;
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.save_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo guardar el instrumento en DataLake', 500, $e);
        }
    }

    public function delete(string $symbol): bool
    {
        $clean = strtoupper(trim($symbol));
        if ($clean === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        try {
            return $this->repository->delete($clean);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.delete_failed', [
                'symbol' => $clean,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo eliminar el instrumento del DataLake', 500, $e);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @param array<string,array<string,mixed>> $map
     */
    private function normalizeItems(array $items, string $type, array &$map): void
    {
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $symbol = strtoupper((string) ($row['symbol'] ?? $row['especie'] ?? ''));
            if ($symbol === '') {
                continue;
            }
            $normalized = [
                'symbol' => $symbol,
                'name' => $row['name'] ?? $row['nombre'] ?? $row['especie'] ?? $symbol,
                'tipo' => $type,
                'panel' => $row['panel'] ?? null,
                'mercado' => $row['mercado'] ?? null,
                'currency' => $row['currency'] ?? $row['moneda'] ?? null,
                'source' => $row['source'] ?? $row['provider'] ?? 'rava',
                'as_of' => $row['as_of'] ?? $row['fecha'] ?? null,
                'price' => $this->floatOrNull($row['ultimo'] ?? $row['price'] ?? $row['close'] ?? null),
                'var_pct' => $this->floatOrNull($row['variacion'] ?? null),
                'var_mtd' => $this->floatOrNull($row['var_mtd'] ?? null),
                'var_ytd' => $this->floatOrNull($row['var_ytd'] ?? null),
                'volume_nominal' => $this->floatOrNull($row['volumen_nominal'] ?? $row['volnominal'] ?? null),
                'volume_efectivo' => $this->floatOrNull($row['volumen_efectivo'] ?? $row['volefectivo'] ?? null),
                'anterior' => $this->floatOrNull($row['anterior'] ?? null),
                'apertura' => $this->floatOrNull($row['apertura'] ?? null),
                'maximo' => $this->floatOrNull($row['maximo'] ?? null),
                'minimo' => $this->floatOrNull($row['minimo'] ?? null),
                'operaciones' => isset($row['operaciones']) && is_numeric($row['operaciones']) ? (int) $row['operaciones'] : null,
                'meta' => [
                    'especie' => $row['especie'] ?? null,
                    'plazo' => $row['plazo'] ?? null,
                    'ratio' => $row['ratio'] ?? null,
                    'segment' => $row['segment'] ?? null,
                ],
            ];

            if (isset($map[$symbol])) {
                $map[$symbol] = $this->mergeInstrument($map[$symbol], $normalized);
            } else {
                $map[$symbol] = $normalized;
            }
        }
    }

    /**
     * Prefiere datos con as_of más reciente o con precio presente.
     *
     * @param array<string,mixed> $current
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeInstrument(array $current, array $incoming): array
    {
        $currentTs = $this->asOfTimestamp($current['as_of'] ?? null);
        $incomingTs = $this->asOfTimestamp($incoming['as_of'] ?? null);

        if ($incomingTs !== null && ($currentTs === null || $incomingTs >= $currentTs)) {
            return array_merge($current, $incoming);
        }

        // Si no hay as_of nuevo pero hay precio, completar campos faltantes
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            if (!isset($current[$key]) || $current[$key] === null || $current[$key] === '') {
                $current[$key] = $value;
            }
        }
        return $current;
    }

    private function asOfTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $ts = strtotime($value);
        return $ts === false ? null : $ts;
    }

    private function floatOrNull(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeSingle(array $row): array
    {
        $symbol = strtoupper((string) ($row['symbol'] ?? $row['especie'] ?? ''));
        return [
            'symbol' => $symbol,
            'name' => $row['name'] ?? $row['nombre'] ?? $symbol,
            'tipo' => $row['tipo'] ?? $row['type'] ?? null,
            'panel' => $row['panel'] ?? null,
            'mercado' => $row['mercado'] ?? $row['exchange'] ?? null,
            'currency' => $row['currency'] ?? null,
            'source' => $row['source'] ?? $row['provider'] ?? 'manual',
            'as_of' => $row['as_of'] ?? $row['fecha'] ?? null,
            'price' => $this->floatOrNull($row['price'] ?? $row['ultimo'] ?? null),
            'var_pct' => $this->floatOrNull($row['var_pct'] ?? $row['variacion'] ?? null),
            'var_mtd' => $this->floatOrNull($row['var_mtd'] ?? null),
            'var_ytd' => $this->floatOrNull($row['var_ytd'] ?? null),
            'volume_nominal' => $this->floatOrNull($row['volume_nominal'] ?? null),
            'volume_efectivo' => $this->floatOrNull($row['volume_efectivo'] ?? null),
            'anterior' => $this->floatOrNull($row['anterior'] ?? null),
            'apertura' => $this->floatOrNull($row['apertura'] ?? null),
            'maximo' => $this->floatOrNull($row['maximo'] ?? null),
            'minimo' => $this->floatOrNull($row['minimo'] ?? null),
            'operaciones' => isset($row['operaciones']) && is_numeric($row['operaciones']) ? (int) $row['operaciones'] : null,
            'meta' => $row['meta'] ?? null,
        ];
    }
}
