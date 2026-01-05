<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData;

use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\RavaHistoricosClient;

/**
 * Servicio Application para histórico diario desde RAVA.
 */
final class RavaHistoricosService
{
    private RavaHistoricosClient $client;
    private LoggerInterface $logger;
    private \DateTimeZone $tz;

    public function __construct(RavaHistoricosClient $client, LoggerInterface $logger)
    {
        $this->client = $client;
        $this->logger = $logger;
        $this->tz = new \DateTimeZone('America/Argentina/Buenos_Aires');
    }

    /**
     * Devuelve histórico normalizado para una especie.
     *
     * @return array{items:array<int,array<string,mixed>>,meta:array<string,mixed>}
     */
    public function historicos(string $especie): array
    {
        $symbol = trim($especie);
        if ($symbol === '') {
            throw new \RuntimeException('Parámetro especie requerido', 422);
        }

        try {
            $raw = $this->client->fetchHistoricos($symbol);
            $items = $this->normalizeItems($raw['body'], $symbol);
            return [
                'items' => $items,
                'meta' => [
                    'count' => count($items),
                    'symbol' => $symbol,
                    'from' => $this->minDate($items),
                    'to' => $this->maxDate($items),
                    'as_of' => $this->maxDate($items),
                    'source' => 'rava',
                    'cached' => false,
                    'stale' => false,
                ],
            ];
        } catch (\Throwable $exception) {
            $this->logger->info('rava.historicos.fetch_failed', [
                'especie' => $symbol,
                'message' => $exception->getMessage(),
            ]);
            throw new \RuntimeException('No se pudo obtener histórico desde RAVA', 502);
        }
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function normalizeItems(array $rows, string $symbol): array
    {
        $items = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $items[] = $this->normalizeItem($row, $symbol);
        }

        usort($items, static function (array $a, array $b): int {
            $da = $a['fecha'] ?? '';
            $db = $b['fecha'] ?? '';
            return strcmp((string) $db, (string) $da);
        });

        return $items;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function normalizeItem(array $row, string $symbol): array
    {
        $fecha = $this->stringOrNull($row['fecha'] ?? null);

        return [
            'symbol' => $symbol,
            'especie' => $this->stringOrNull($row['especie'] ?? $symbol),
            'fecha' => $fecha,
            'apertura' => $this->floatOrNull($row['apertura'] ?? null),
            'maximo' => $this->floatOrNull($row['maximo'] ?? null),
            'minimo' => $this->floatOrNull($row['minimo'] ?? null),
            'cierre' => $this->floatOrNull($row['cierre'] ?? null),
            'volumen' => $this->floatOrNull($row['volumen'] ?? ($row['volumen_nominal'] ?? null)),
            'variacion' => $this->floatOrNull($row['variacion'] ?? null),
            'ajuste' => $this->floatOrNull($row['ajuste'] ?? null),
            'as_of' => $this->buildSnapshotAt($fecha),
            'source' => 'rava',
        ];
    }

    private function floatOrNull($value): ?float
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function stringOrNull($value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function buildSnapshotAt(?string $date): ?string
    {
        if ($date === null || $date === '') {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($date, $this->tz);
            return $dt->format(DATE_ATOM);
        } catch (\Throwable $e) {
            $this->logger->info('rava.historicos.datetime_parse_failed', [
                'value' => $date,
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function minDate(array $items): ?string
    {
        $dates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fecha = $item['fecha'] ?? null;
            if (is_string($fecha) && trim($fecha) !== '') {
                $dates[] = trim($fecha);
            }
        }
        if (empty($dates)) {
            return null;
        }
        sort($dates);
        return $dates[0];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     */
    private function maxDate(array $items): ?string
    {
        $dates = [];
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $fecha = $item['fecha'] ?? null;
            if (is_string($fecha) && trim($fecha) !== '') {
                $dates[] = trim($fecha);
            }
        }
        if (empty($dates)) {
            return null;
        }
        rsort($dates);
        return $dates[0];
    }
}
