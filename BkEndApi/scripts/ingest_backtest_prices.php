<?php
declare(strict_types=1);

use FinHub\Infrastructure\Config\ApplicationBootstrap;
use FinHub\Infrastructure\Logging\LoggerInterface;

require __DIR__ . '/../autoload.php';
require __DIR__ . '/../config/ApplicationBootstrap.php';

/**
 * Script de ingesta de históricos diarios para backtesting.
 * Fuente actual: RAVA históricos (bar OHLCV diario).
 *
 * Uso:
 *   php scripts/ingest_backtest_prices.php --symbols=GGAL,YPF --start=2023-01-01 --end=2024-01-01
 *
 * Efecto:
 * - Asegura que cada símbolo exista en instruments.
 * - Descarga históricos de RAVA.
 * - Inserta/actualiza precios en prices (as_of diario) con ON DUPLICATE.
 * - Reporta resumen por símbolo (insertados/actualizados/skips y rango cargado).
 */
final class BacktestPriceIngest
{
    private \PDO $pdo;
    private LoggerInterface $logger;
    private \FinHub\Application\MarketData\RavaHistoricosService $ravaHistoricosService;
    private array $symbols;
    private \DateTimeImmutable $start;
    private \DateTimeImmutable $end;
    private int $portfolioId;

    public function __construct(array $symbols, \DateTimeImmutable $start, \DateTimeImmutable $end, int $portfolioId = 1)
    {
        $bootstrap = new ApplicationBootstrap(__DIR__ . '/..');
        $container = $bootstrap->createContainer();
        $this->pdo = $container->get('pdo');
        $this->logger = $container->get('logger');
        $this->ravaHistoricosService = $container->get('rava_historicos_service');
        $this->symbols = $symbols;
        $this->start = $start;
        $this->end = $end;
        $this->portfolioId = $portfolioId;
    }

    public function run(): int
    {
        $summary = [
            'started_at' => date('c'),
            'finished_at' => null,
            'total_symbols' => count($this->symbols),
            'ok' => 0,
            'failed' => 0,
            'symbols' => [],
            'errors' => [],
        ];

        foreach ($this->symbols as $symbol) {
            $symbolSummary = [
                'symbol' => $symbol,
                'inserted' => 0,
                'updated' => 0,
                'skipped' => 0,
                'rows_in_range' => 0,
                'min_as_of' => null,
                'max_as_of' => null,
                'errors' => [],
            ];

            try {
                $instrumentId = $this->ensureInstrument($symbol);
                $bars = $this->fetchBars($symbol);
                if (empty($bars)) {
                    $symbolSummary['errors'][] = 'Sin datos en rango';
                    $summary['errors'][] = ['symbol' => $symbol, 'reason' => 'Sin datos en rango'];
                    $summary['failed']++;
                    $summary['symbols'][] = $symbolSummary;
                    continue;
                }

                [$inserted, $updated, $skipped] = $this->persistBars($instrumentId, $bars);
                $symbolSummary['inserted'] = $inserted;
                $symbolSummary['updated'] = $updated;
                $symbolSummary['skipped'] = $skipped;

                $stats = $this->fetchRangeStats($instrumentId);
                $symbolSummary['rows_in_range'] = $stats['rows'];
                $symbolSummary['min_as_of'] = $stats['min'];
                $symbolSummary['max_as_of'] = $stats['max'];

                $summary['ok']++;
            } catch (\Throwable $e) {
                $this->logger->error('ingest.backtest_prices.symbol.error', [
                    'symbol' => $symbol,
                    'message' => $e->getMessage(),
                ]);
                $symbolSummary['errors'][] = $e->getMessage();
                $summary['errors'][] = ['symbol' => $symbol, 'reason' => $e->getMessage()];
                $summary['failed']++;
            }

            $summary['symbols'][] = $symbolSummary;
        }

        $summary['finished_at'] = date('c');
        $this->renderReport($summary);

        return $summary['ok'] > 0 ? 0 : 1;
    }

    /**
     * Devuelve el id del instrumento, creándolo si no existe.
     */
    private function ensureInstrument(string $symbol): int
    {
        $stmt = $this->pdo->prepare('SELECT id FROM portfolio_instruments WHERE portfolio_id = :portfolio_id AND especie = :especie LIMIT 1');
        $stmt->execute([':portfolio_id' => $this->portfolioId, ':especie' => $symbol]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }

        $insert = $this->pdo->prepare(
            'INSERT INTO portfolio_instruments (portfolio_id, especie, name, exchange, currency, country, type, mic_code) VALUES (:portfolio_id, :especie, :name, :exchange, :currency, :country, :type, :mic_code)'
        );
        $insert->execute([
            ':portfolio_id' => $this->portfolioId,
            ':especie' => $symbol,
            ':name' => $symbol,
            ':type' => 'stock',
            ':exchange' => 'BCBA',
            ':currency' => 'ARS',
            ':country' => 'AR',
            ':mic_code' => '',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchBars(string $symbol): array
    {
        $result = $this->ravaHistoricosService->historicos($symbol);
        $items = $result['items'] ?? $result['data'] ?? [];
        $bars = [];
        foreach ($items as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fecha = $row['fecha'] ?? null;
            if (!is_string($fecha) || trim($fecha) === '') {
                continue;
            }
            try {
                $dt = new \DateTimeImmutable($fecha);
            } catch (\Throwable) {
                continue;
            }
            if ($dt < $this->start || $dt > $this->end) {
                continue;
            }
            $bars[] = [
                'as_of' => $dt->format('Y-m-d'),
                'open' => $this->floatOrNull($row['apertura'] ?? null),
                'high' => $this->floatOrNull($row['maximo'] ?? null),
                'low' => $this->floatOrNull($row['minimo'] ?? null),
                'close' => $this->floatOrNull($row['cierre'] ?? null),
                'volume' => $this->intOrZero($row['volumen'] ?? $row['volumen_nominal'] ?? null),
                'source' => 'rava',
            ];
        }
        return $bars;
    }

    /**
     * @param array<int,array<string,mixed>> $bars
     * @return array{0:int,1:int,2:int}
     */
    private function persistBars(int $instrumentId, array $bars): array
    {
        $inserted = 0;
        $updated = 0;
        $skipped = 0;

        $sql = <<<'SQL'
INSERT INTO prices (instrument_id, as_of, open, high, low, close, volume, source)
VALUES (:instrument_id, :as_of, :open, :high, :low, :close, :volume, :source)
ON DUPLICATE KEY UPDATE
  open = VALUES(open),
  high = VALUES(high),
  low = VALUES(low),
  close = VALUES(close),
  volume = VALUES(volume),
  source = VALUES(source)
SQL;
        $stmt = $this->pdo->prepare($sql);

        $this->pdo->beginTransaction();
        try {
            foreach ($bars as $bar) {
                if ($bar['as_of'] === null || $bar['open'] === null || $bar['high'] === null || $bar['low'] === null || $bar['close'] === null) {
                    $skipped++;
                    continue;
                }
                $stmt->execute([
                    ':instrument_id' => $instrumentId,
                    ':as_of' => $bar['as_of'],
                    ':open' => $bar['open'],
                    ':high' => $bar['high'],
                    ':low' => $bar['low'],
                    ':close' => $bar['close'],
                    ':volume' => $bar['volume'],
                    ':source' => $bar['source'],
                ]);
                $rc = $stmt->rowCount();
                if ($rc === 1) {
                    $inserted++;
                } elseif ($rc >= 2) {
                    $updated++;
                } else {
                    $skipped++;
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return [$inserted, $updated, $skipped];
    }

    /**
     * @return array{rows:int,min:?(string),max:?(string)}
     */
    private function fetchRangeStats(int $instrumentId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) AS rows_count, MIN(as_of) AS min_as_of, MAX(as_of) AS max_as_of FROM prices WHERE instrument_id = :id AND as_of BETWEEN :start AND :end'
        );
        $stmt->execute([
            ':id' => $instrumentId,
            ':start' => $this->start->format('Y-m-d'),
            ':end' => $this->end->format('Y-m-d'),
        ]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
        return [
            'rows' => (int) ($row['rows_count'] ?? 0),
            'min' => $row['min_as_of'] ?? null,
            'max' => $row['max_as_of'] ?? null,
        ];
    }

    private function floatOrNull(mixed $value): ?float
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

    private function intOrZero(mixed $value): int
    {
        if ($value === null || $value === '' || $value === false) {
            return 0;
        }
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            return (int) round($value);
        }
        $normalized = str_replace(',', '.', (string) $value);
        return is_numeric($normalized) ? (int) round((float) $normalized) : 0;
    }

    /**
     * Imprime reporte consolidado en consola.
     */
    private function renderReport(array $summary): void
    {
        echo "=== Ingesta de históricos para backtest ===\n";
        echo "Inicio: {$summary['started_at']} | Fin: {$summary['finished_at']}\n";
        echo "Símbolos: {$summary['total_symbols']} | OK: {$summary['ok']} | Fallidos: {$summary['failed']}\n\n";

        foreach ($summary['symbols'] as $item) {
            $line = sprintf(
                "[%s] ins=%d upd=%d skip=%d | rows=%d | rango=%s .. %s",
                $item['symbol'],
                $item['inserted'],
                $item['updated'],
                $item['skipped'],
                $item['rows_in_range'],
                $item['min_as_of'] ?? 'n/d',
                $item['max_as_of'] ?? 'n/d'
            );
            echo $line . "\n";
            foreach ($item['errors'] as $err) {
                echo "  - error: {$err}\n";
            }
        }

        if (!empty($summary['errors'])) {
            echo "\nErrores:\n";
            foreach ($summary['errors'] as $err) {
                echo "- {$err['symbol']}: {$err['reason']}\n";
            }
        }
    }
}

function usage(): void
{
    $msg = <<<TXT
Uso: php scripts/ingest_backtest_prices.php --symbols=GGAL,YPF --start=YYYY-MM-DD --end=YYYY-MM-DD [--portfolio=ID]
  --symbols   Lista separada por comas (obligatorio)
  --start     Fecha inicial (YYYY-MM-DD, obligatoria)
  --end       Fecha final (YYYY-MM-DD, obligatoria)
  --portfolio ID de portfolio al que se asocian los instrumentos (por defecto 1)

Fuente fija: RAVA históricos. Inserta/actualiza tabla prices.
TXT;
    fwrite(STDERR, $msg . PHP_EOL);
}

function parseDate(string $value, string $name): \DateTimeImmutable
{
    $trimmed = trim($value);
    if ($trimmed === '') {
        throw new \InvalidArgumentException(sprintf('Parámetro --%s requerido', $name));
    }
    try {
        return new \DateTimeImmutable($trimmed);
    } catch (\Throwable) {
        throw new \InvalidArgumentException(sprintf('Fecha inválida para --%s', $name));
    }
}

// Ejecución CLI directa
if (php_sapi_name() === 'cli' && basename(__FILE__) === basename($_SERVER['SCRIPT_FILENAME'] ?? '')) {
    $options = getopt('', ['symbols:', 'start:', 'end:', 'portfolio::']);
    if (!isset($options['symbols'], $options['start'], $options['end'])) {
        usage();
        exit(1);
    }

    $symbolsInput = array_map(
        static fn ($s) => strtoupper(trim((string) $s)),
        explode(',', (string) $options['symbols'])
    );
    $symbols = array_values(array_filter($symbolsInput, static fn ($s) => $s !== ''));
    if (empty($symbols)) {
        fwrite(STDERR, "No se encontraron símbolos válidos en --symbols\n");
        exit(1);
    }

    try {
        $start = parseDate((string) $options['start'], 'start');
        $end = parseDate((string) $options['end'], 'end');
    } catch (\InvalidArgumentException $e) {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    if ($start > $end) {
        fwrite(STDERR, "--start no puede ser mayor que --end\n");
        exit(1);
    }

    $portfolioId = isset($options['portfolio']) ? (int) $options['portfolio'] : 1;
    $app = new BacktestPriceIngest($symbols, $start, $end, $portfolioId);
    exit($app->run());
}
