<?php
declare(strict_types=1);

namespace FinHub\Application\Ingestion;

use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Stub: disponibilidad de datos marcada como lista tras eliminar R2Lite.
 */
final class DataReadinessService
{
    private LoggerInterface $logger;

    public function __construct(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    /**
     * @param array<int,string> $symbols
     * @return array<string,mixed>
     */
    public function ensureSeriesReady(array $symbols, string $period, int $minPoints, int $maxAgeDays = 2): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $this->logger->info('data_readiness.stub.ensure_series', ['symbols' => $symbols]);
        return [
            'ready' => true,
            'missing' => [],
            'details' => [],
            'warnings' => [],
            'attempts' => [],
            'start' => null,
            'end' => null,
            'max_age_days' => $maxAgeDays,
        ];
    }

    /**
     * @param array<int,string> $symbols
     * @return array<string,mixed>
     */
    public function ensureBacktestReady(array $symbols, \DateTimeImmutable $start, \DateTimeImmutable $end, int $maxAgeDays = 2): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $this->logger->info('data_readiness.stub.ensure_backtest', ['symbols' => $symbols]);
        return [
            'ready' => true,
            'missing' => [],
            'details' => [],
            'warnings' => [],
            'attempts' => [],
        ];
    }

    /**
     * @param array<int,string> $symbols
     * @return array<int,string>
     */
    private function normalizeSymbols(array $symbols): array
    {
        $clean = [];
        foreach ($symbols as $symbol) {
            $s = strtoupper(trim((string) $symbol));
            if ($s === '') {
                continue;
            }
            $clean[] = $s;
        }
        return array_values(array_unique($clean));
    }
}
