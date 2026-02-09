<?php
declare(strict_types=1);

namespace FinHub\Application\DataLake;

use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Servicio de catálogo de instrumentos (DataLake SERVING).
 * Lee y mantiene el índice de instrumentos disponible en DataLake.
 */
final class InstrumentCatalogService
{
    private InstrumentCatalogRepositoryInterface $repository;
    private LoggerInterface $logger;
    private ?\FinHub\Application\Portfolio\PortfolioService $portfolioService = null;

    public function __construct(
        InstrumentCatalogRepositoryInterface $repository,
        LoggerInterface $logger,
        ?\FinHub\Application\Portfolio\PortfolioService $portfolioService = null
    ) {
        $this->repository = $repository;
        $this->logger = $logger;
        $this->portfolioService = $portfolioService;
    }

    /**
     * Sincroniza catálogo desde fuentes externas y persiste en DataLake.
     *
     * @return array<string,mixed>
     */
    public function syncFromRava(): array
    {
        throw new \RuntimeException('Sincronización de catálogo deshabilitada: proveedores removidos.', 501);
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

    /**
     * Busca instrumentos filtrando por texto y metadatos.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search(?string $query, ?string $tipo, ?string $panel, ?string $mercado, ?string $currency, int $limit = 200, int $offset = 0): array
    {
        $limit = $limit > 0 ? $limit : 200;
        $offset = $offset > 0 ? $offset : 0;
        try {
            return $this->repository->searchLatest($query, $tipo, $panel, $mercado, $currency, $limit, $offset);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.catalog.search_failed', [
                'query' => $query,
                'tipo' => $tipo,
                'panel' => $panel,
                'mercado' => $mercado,
                'currency' => $currency,
                'message' => $e->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo buscar en el catálogo de DataLake', 500, $e);
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
     * Captura snapshot histórico para símbolos presentes en portafolios (deduplicados).
     */
    public function capturePortfolioSymbols(int $userId): array
    {
        if ($this->portfolioService === null) {
            throw new \RuntimeException('Servicio de portafolios no disponible', 500);
        }
        $symbols = $this->portfolioService->listSymbols($userId);
        $unique = array_values(array_unique(array_map(
            static fn ($s) => strtoupper(trim((string) $s)),
            $symbols
        )));
        $captured = 0;
        $failed = [];
        foreach ($unique as $symbol) {
            try {
                $data = $this->resolveSymbolData($symbol);
                $this->repository->upsertOne($data);
                $captured++;
            } catch (\Throwable $e) {
                $failed[] = ['symbol' => $symbol, 'reason' => $e->getMessage()];
                $this->logger->info('datalake.catalog.capture_failed', [
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        return [
            'captured' => $captured,
            'failed' => $failed,
            'total' => count($unique),
        ];
    }

    /**
     * Lista histórico de snapshots.
     *
     * @return array<int,array<string,mixed>>
     */
    public function history(?string $symbol = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?\DateTimeImmutable $capturedAt = null): array
    {
        return $this->repository->history($symbol, $from, $to, $capturedAt);
    }

    /**
     * @return array<int,array{captured_at:string,count:int}>
     */
    public function captures(): array
    {
        return $this->repository->listCaptures();
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
                'source' => $row['source'] ?? $row['provider'] ?? 'ingestion',
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
        $now = new \DateTimeImmutable();
        $capturedAt = $row['captured_at'] ?? $now;
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
            'captured_at' => $capturedAt instanceof \DateTimeInterface ? $capturedAt : $now,
        ];
    }

    /**
     * Resuelve datos de un símbolo usando catálogo fresco (<10 min) o proveedor.
     *
     * @return array<string,mixed>
     */
    private function resolveSymbolData(string $symbol): array
    {
        $latest = $this->find($symbol);
        $now = new \DateTimeImmutable();
        $fresh = false;
        if ($latest !== null) {
            $ts = $latest['as_of'] ?? $latest['captured_at'] ?? null;
            if (is_string($ts)) {
                $dt = new \DateTimeImmutable($ts);
                $fresh = ($now->getTimestamp() - $dt->getTimestamp()) <= 600;
            }
        }
        if ($latest !== null && $fresh && isset($latest['price'])) {
            $latest['captured_at'] = $now;
            return $latest;
        }
        return $this->normalizeSingle([
            'symbol' => $symbol,
            'name' => $latest['name'] ?? $symbol,
            'price' => $latest['price'] ?? null,
            'currency' => $latest['currency'] ?? null,
            'as_of' => $latest['as_of'] ?? null,
            'provider' => $latest['source'] ?? 'manual',
            'var_pct' => $latest['var_pct'] ?? null,
            'var_mtd' => $latest['var_mtd'] ?? null,
            'var_ytd' => $latest['var_ytd'] ?? null,
        ]);
    }
}
