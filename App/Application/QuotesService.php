<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\FinnhubService;

class QuotesService
{
    private const CATEGORY_LIMIT = 10;

    /**
     * @var string[]
     */
    private array $supportedCategories = ['stocks', 'etfs', 'indices', 'forex', 'crypto'];

    public function __construct(
        private readonly FinnhubService $finnhubService,
        private readonly string $cacheDirectory,
        private readonly bool $cronActive,
        private readonly int $cronInterval,
        private readonly string $hourStart,
        private readonly string $hourEnd
    ) {
        if (!is_dir($this->cacheDirectory)) {
            mkdir($this->cacheDirectory, 0775, true);
        }
    }

    public function getSupportedCategories(): array
    {
        return $this->supportedCategories;
    }

    /**
     * @return array{category:string,updated_at:int,items:array<int,array<string,mixed>>}
     */
    public function getQuotes(string $category): array
    {
        $normalized = $this->normalizeCategory($category);
        $cache = $this->readCache($normalized);

        if ($this->shouldRefresh($cache)) {
            $items = $this->fetchFromApi($normalized);
            $cache = [
                'category' => $normalized,
                'updated_at' => time(),
                'items' => $items,
            ];
            $this->writeCache($normalized, $cache);
        }

        if ($cache === null) {
            throw new \RuntimeException("No quotes available for category {$normalized}");
        }

        return $cache;
    }

    private function normalizeCategory(string $category): string
    {
        $normalized = strtolower($category);
        if (!in_array($normalized, $this->supportedCategories, true)) {
            throw new \InvalidArgumentException("Invalid category {$category}");
        }

        return $normalized;
    }

    /**
     * @param array<string,mixed>|null $cache
     */
    private function shouldRefresh(?array $cache): bool
    {
        if ($cache === null) {
            return true;
        }

        if (!$this->withinWindow()) {
            return false;
        }

        if ($this->cronActive) {
            // Cron supposed to refresh externally; fallback only if cache stale over 15 min.
            return time() - (int)$cache['updated_at'] > 900;
        }

        return time() - (int)$cache['updated_at'] >= max(60, $this->cronInterval);
    }

    private function withinWindow(): bool
    {
        $start = $this->parseMinutes($this->hourStart);
        $end = $this->parseMinutes($this->hourEnd);
        $now = (int)date('H') * 60 + (int)date('i');

        if ($start === null || $end === null) {
            return true;
        }

        if ($start <= $end) {
            return $now >= $start && $now <= $end;
        }

        // Window spanning midnight
        return $now >= $start || $now <= $end;
    }

    private function parseMinutes(string $value): ?int
    {
        if (!preg_match('/^\d{2}:\d{2}$/', $value)) {
            return null;
        }
        [$hour, $minute] = array_map('intval', explode(':', $value));
        return $hour * 60 + $minute;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function fetchFromApi(string $category): array
    {
        $symbols = $this->finnhubService->getSymbols($category);
        $items = [];

        foreach ($symbols as $entry) {
            $symbol = $this->resolveSymbolFromEntry($entry);
            if ($symbol === null) {
                continue;
            }

            try {
                $quote = $this->finnhubService->getQuote($symbol);
                $price = (float)($quote['c'] ?? 0.0);
                $previous = (float)($quote['pc'] ?? 0.0);
                if ($price <= 0) {
                    continue;
                }

                $change = $price - $previous;
                $percent = $previous > 0 ? ($change / $previous) * 100 : 0.0;

                $items[] = [
                    'symbol' => $symbol,
                    'price' => round($price, 4),
                    'change' => round($change, 4),
                    'percent' => round($percent, 2),
                    'open' => $quote['o'] ?? null,
                    'high' => $quote['h'] ?? null,
                    'low' => $quote['l'] ?? null,
                    'previousClose' => $previous,
                ];
            } catch (\Throwable) {
                continue;
            }

            if (count($items) >= self::CATEGORY_LIMIT) {
                break;
            }
        }

        return $items;
    }

    /**
     * @param array<string,mixed> $entry
     */
    private function resolveSymbolFromEntry(array $entry): ?string
    {
        foreach (['symbol', 'displaySymbol', 'code'] as $key) {
            if (!empty($entry[$key]) && is_string($entry[$key])) {
                return $entry[$key];
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readCache(string $category): ?array
    {
        $file = $this->cacheFile($category);
        if (!is_file($file)) {
            return null;
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $data
     */
    private function writeCache(string $category, array $data): void
    {
        file_put_contents($this->cacheFile($category), json_encode($data, JSON_PRETTY_PRINT));
    }

    private function cacheFile(string $category): string
    {
        return rtrim($this->cacheDirectory, '/') . '/' . $category . '.json';
    }
}
