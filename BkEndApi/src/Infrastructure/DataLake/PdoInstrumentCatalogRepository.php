<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\DataLake;

use FinHub\Application\DataLake\InstrumentCatalogRepositoryInterface;
use PDO;

/**
 * Repositorio PDO para catálogo de instrumentos (DataLake SERVING).
 */
final class PdoInstrumentCatalogRepository implements InstrumentCatalogRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function upsertMany(array $items): int
    {
        if (empty($items)) {
            return 0;
        }

        $sql = <<<'SQL'
INSERT INTO dl_instrument_catalog
    (symbol, name, tipo, panel, mercado, currency, source, as_of, price, var_pct, var_mtd, var_ytd, volume_nominal, volume_efectivo, anterior, apertura, maximo, minimo, operaciones, meta_json, captured_at)
VALUES
    (:symbol, :name, :tipo, :panel, :mercado, :currency, :source, :as_of, :price, :var_pct, :var_mtd, :var_ytd, :volume_nominal, :volume_efectivo, :anterior, :apertura, :maximo, :minimo, :operaciones, :meta_json, :captured_at)
SQL;

        $stmt = $this->pdo->prepare($sql);
        $count = 0;

        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $asOf = $this->normalizeDateTime($item['as_of'] ?? null);
            $meta = $item['meta'] ?? [];
            $capturedAt = $this->normalizeDateTime($item['captured_at'] ?? new \DateTimeImmutable());
            $stmt->execute([
                'symbol' => strtoupper((string) ($item['symbol'] ?? '')),
                'name' => $item['name'] ?? null,
                'tipo' => $item['tipo'] ?? null,
                'panel' => $item['panel'] ?? null,
                'mercado' => $item['mercado'] ?? null,
                'currency' => $item['currency'] ?? null,
                'source' => $item['source'] ?? null,
                'as_of' => $asOf,
                'price' => $this->floatOrNull($item['price'] ?? null),
                'var_pct' => $this->floatOrNull($item['var_pct'] ?? null),
                'var_mtd' => $this->floatOrNull($item['var_mtd'] ?? null),
                'var_ytd' => $this->floatOrNull($item['var_ytd'] ?? null),
                'volume_nominal' => $this->floatOrNull($item['volume_nominal'] ?? null),
                'volume_efectivo' => $this->floatOrNull($item['volume_efectivo'] ?? null),
                'anterior' => $this->floatOrNull($item['anterior'] ?? null),
                'apertura' => $this->floatOrNull($item['apertura'] ?? null),
                'maximo' => $this->floatOrNull($item['maximo'] ?? null),
                'minimo' => $this->floatOrNull($item['minimo'] ?? null),
                'operaciones' => isset($item['operaciones']) && is_numeric($item['operaciones']) ? (int) $item['operaciones'] : null,
                'meta_json' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                'captured_at' => $capturedAt,
            ]);
            $count += $stmt->rowCount() > 0 ? 1 : 0;
        }

        return $count;
    }

    public function upsertOne(array $item): bool
    {
        $sql = <<<'SQL'
INSERT INTO dl_instrument_catalog
    (symbol, name, tipo, panel, mercado, currency, source, as_of, price, var_pct, var_mtd, var_ytd, volume_nominal, volume_efectivo, anterior, apertura, maximo, minimo, operaciones, meta_json, captured_at)
VALUES
    (:symbol, :name, :tipo, :panel, :mercado, :currency, :source, :as_of, :price, :var_pct, :var_mtd, :var_ytd, :volume_nominal, :volume_efectivo, :anterior, :apertura, :maximo, :minimo, :operaciones, :meta_json, :captured_at)
SQL;
        $stmt = $this->pdo->prepare($sql);
        $asOf = $this->normalizeDateTime($item['as_of'] ?? null);
        $meta = $item['meta'] ?? [];
        $capturedAt = $this->normalizeDateTime($item['captured_at'] ?? new \DateTimeImmutable());
        $stmt->execute([
            'symbol' => strtoupper((string) ($item['symbol'] ?? '')),
            'name' => $item['name'] ?? null,
            'tipo' => $item['tipo'] ?? null,
            'panel' => $item['panel'] ?? null,
            'mercado' => $item['mercado'] ?? null,
            'currency' => $item['currency'] ?? null,
            'source' => $item['source'] ?? null,
            'as_of' => $asOf,
            'price' => $this->floatOrNull($item['price'] ?? null),
            'var_pct' => $this->floatOrNull($item['var_pct'] ?? null),
            'var_mtd' => $this->floatOrNull($item['var_mtd'] ?? null),
            'var_ytd' => $this->floatOrNull($item['var_ytd'] ?? null),
            'volume_nominal' => $this->floatOrNull($item['volume_nominal'] ?? null),
            'volume_efectivo' => $this->floatOrNull($item['volume_efectivo'] ?? null),
            'anterior' => $this->floatOrNull($item['anterior'] ?? null),
            'apertura' => $this->floatOrNull($item['apertura'] ?? null),
            'maximo' => $this->floatOrNull($item['maximo'] ?? null),
            'minimo' => $this->floatOrNull($item['minimo'] ?? null),
            'operaciones' => isset($item['operaciones']) && is_numeric($item['operaciones']) ? (int) $item['operaciones'] : null,
            'meta_json' => !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            'captured_at' => $capturedAt,
        ]);
        return true;
    }

    public function listAll(): array
    {
        $sql = <<<'SQL'
SELECT c.*
FROM dl_instrument_catalog c
JOIN (
    SELECT symbol, MAX(captured_at) AS max_captured
    FROM dl_instrument_catalog
    GROUP BY symbol
) latest ON c.symbol = latest.symbol AND c.captured_at = latest.max_captured
ORDER BY c.symbol ASC
SQL;
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(function (array $row): array {
            return $this->hydrate($row);
        }, $rows);
    }

    public function findBySymbol(string $symbol): ?array
    {
        $stmt = $this->pdo->prepare('SELECT symbol, name, tipo, panel, mercado, currency, source, as_of, price, var_pct, var_mtd, var_ytd, volume_nominal, volume_efectivo, anterior, apertura, maximo, minimo, operaciones, meta_json, captured_at, updated_at FROM dl_instrument_catalog WHERE symbol = :symbol ORDER BY captured_at DESC LIMIT 1');
        $stmt->execute(['symbol' => strtoupper($symbol)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return $this->hydrate($row);
    }

    public function delete(string $symbol): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM dl_instrument_catalog WHERE symbol = :symbol');
        return $stmt->execute(['symbol' => strtoupper($symbol)]);
    }

    public function history(?string $symbol = null, ?\DateTimeImmutable $from = null, ?\DateTimeImmutable $to = null, ?\DateTimeImmutable $capturedAt = null): array
    {
        $where = [];
        $params = [];
        if ($symbol !== null && $symbol !== '') {
            $where[] = 'symbol = :symbol';
            $params[':symbol'] = strtoupper($symbol);
        }
        if ($from !== null) {
            $where[] = 'captured_at >= :from';
            $params[':from'] = $from->format('Y-m-d H:i:s');
        }
        if ($to !== null) {
            $where[] = 'captured_at <= :to';
            $params[':to'] = $to->format('Y-m-d H:i:s');
        }
        if ($capturedAt !== null) {
            $where[] = 'captured_at >= :captured_from AND captured_at < :captured_to';
            $params[':captured_from'] = $capturedAt->format('Y-m-d H:i:00');
            $params[':captured_to'] = $capturedAt->modify('+1 minute')->format('Y-m-d H:i:00');
        }
        $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        $baseSelect = 'SELECT symbol, name, tipo, panel, mercado, currency, source, as_of, price, var_pct, var_mtd, var_ytd, volume_nominal, volume_efectivo, anterior, apertura, maximo, minimo, operaciones, meta_json, captured_at, updated_at FROM dl_instrument_catalog %s ORDER BY captured_at DESC';
        $sql = sprintf($baseSelect, $whereSql);
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(fn ($row) => $this->hydrate($row), $rows);
        } catch (\Throwable $e) {
            // Fallback por si la columna captured_at no existe (schema antiguo)
            $fallbackSelect = 'SELECT symbol, name, tipo, panel, mercado, currency, source, as_of, price, var_pct, var_mtd, var_ytd, volume_nominal, volume_efectivo, anterior, apertura, maximo, minimo, operaciones, meta_json, updated_at AS captured_at, updated_at FROM dl_instrument_catalog %s ORDER BY updated_at DESC';
            $fbSql = sprintf($fallbackSelect, $whereSql);
            $stmt = $this->pdo->prepare($fbSql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            return array_map(fn ($row) => $this->hydrate($row), $rows);
        }
    }

    public function listCaptures(): array
    {
        $query = 'SELECT captured_at, COUNT(*) AS count FROM (SELECT DATE_FORMAT(captured_at, "%Y-%m-%d %H:%i:00") AS captured_at FROM dl_instrument_catalog) t GROUP BY captured_at ORDER BY captured_at DESC';
        try {
            $stmt = $this->pdo->query($query);
        } catch (\Throwable $e) {
            // Fallback si no existe captured_at (schema antiguo)
            $stmt = $this->pdo->query('SELECT captured_at, COUNT(*) AS count FROM (SELECT DATE_FORMAT(updated_at, "%Y-%m-%d %H:%i:00") AS captured_at FROM dl_instrument_catalog) t GROUP BY captured_at ORDER BY captured_at DESC');
        }
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn ($row) => [
            'captured_at' => (string) ($row['captured_at'] ?? ''),
            'count' => (int) ($row['count'] ?? 0),
        ], $rows);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function hydrate(array $row): array
    {
        $meta = $row['meta_json'] ?? null;
        if (is_string($meta)) {
            $decoded = json_decode($meta, true);
            $meta = is_array($decoded) ? $decoded : null;
        }
        return [
            'symbol' => $row['symbol'] ?? '',
            'name' => $row['name'] ?? null,
            'tipo' => $row['tipo'] ?? null,
            'panel' => $row['panel'] ?? null,
            'mercado' => $row['mercado'] ?? null,
            'currency' => $row['currency'] ?? null,
            'source' => $row['source'] ?? null,
            'as_of' => $row['as_of'] ?? null,
            'price' => $row['price'] !== null ? (float) $row['price'] : null,
            'var_pct' => $row['var_pct'] !== null ? (float) $row['var_pct'] : null,
            'var_mtd' => $row['var_mtd'] !== null ? (float) $row['var_mtd'] : null,
            'var_ytd' => $row['var_ytd'] !== null ? (float) $row['var_ytd'] : null,
            'volume_nominal' => $row['volume_nominal'] !== null ? (float) $row['volume_nominal'] : null,
            'volume_efectivo' => $row['volume_efectivo'] !== null ? (float) $row['volume_efectivo'] : null,
            'anterior' => $row['anterior'] !== null ? (float) $row['anterior'] : null,
            'apertura' => $row['apertura'] !== null ? (float) $row['apertura'] : null,
            'maximo' => $row['maximo'] !== null ? (float) $row['maximo'] : null,
            'minimo' => $row['minimo'] !== null ? (float) $row['minimo'] : null,
            'operaciones' => $row['operaciones'] !== null ? (int) $row['operaciones'] : null,
            'meta' => $meta,
            'captured_at' => $row['captured_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ];
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s.u');
        }
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }
        $ts = strtotime($string);
        if ($ts === false) {
            return null;
        }
        $dt = (new \DateTimeImmutable('@' . $ts))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $dt->format('Y-m-d H:i:s');
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
}
