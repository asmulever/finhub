<?php
declare(strict_types=1);

namespace FinHub\Application\Ingestion;

use FinHub\Application\R2Lite\R2LiteService;
use FinHub\Infrastructure\Logging\LoggerInterface;

/**
 * Verifica y dispara ingesta de series vía R2Lite.
 */
final class DataReadinessService
{
    private LoggerInterface $logger;
    private R2LiteService $r2lite;

    public function __construct(LoggerInterface $logger, R2LiteService $r2lite)
    {
        $this->logger = $logger;
        $this->r2lite = $r2lite;
    }

    /**
     * @param array<int,string> $symbols
     * @return array<string,mixed>
     */
    public function ensureSeriesReady(array $symbols, string $period, int $minPoints, int $maxAgeDays = 2): array
    {
        $symbols = $this->normalizeSymbols($symbols);
        $attempts = $this->r2lite->ensureSeries($symbols, 'ACCIONES_AR');
        return $attempts + [
            'ready' => true,
            'missing' => [],
            'warnings' => [],
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
        $attempts = $this->r2lite->ensureSeries($symbols, 'ACCIONES_AR');
        return $attempts + ['missing' => [], 'warnings' => [], 'details' => []];
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
