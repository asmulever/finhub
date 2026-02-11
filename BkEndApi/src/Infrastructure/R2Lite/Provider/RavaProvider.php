<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite\Provider;

use FinHub\Application\R2Lite\ProviderInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Cache\CacheInterface;
use FinHub\Infrastructure\MarketData\RavaViewsClient;

final class RavaProvider extends AbstractHttpProvider implements ProviderInterface
{
    private string $base;
    private RavaViewsClient $client;

    public function __construct(Config $config, LoggerInterface $logger, CacheInterface $cache)
    {
        parent::__construct($logger, $cache, (int) $config->get('RAVA_TIMEOUT_SECONDS', 8));
        $this->base = rtrim((string) $config->get('RAVA_BASE_URL', 'https://www.rava.com'), '/');
        $this->client = new RavaViewsClient($config);
    }

    public function name(): string
    {
        return 'rava';
    }

    public function fetchDaily(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to, string $category): array
    {
        $symbol = strtoupper($symbol);
        // Tomar catálogo completo (no histórico) y buscar el símbolo general en cualquier categoría.
        $catalog = $this->fetchAll();
        $match = $this->findSymbol($catalog, $symbol);
        if ($match === null) {
            return [];
        }
        $asOf = $this->asOf($match);
        return [[
            'symbol' => $symbol,
            'as_of' => $asOf,
            'open' => null,
            'high' => null,
            'low' => null,
            'close' => isset($match['ultimo']) && is_numeric($match['ultimo']) ? (float) $match['ultimo'] : null,
            'volume' => null,
            'currency' => $match['currency'] ?? $this->currencyFor((string) ($match['category'] ?? $category)),
            'provider' => $this->name(),
        ]];
    }

    private function fetchAll(): array
    {
        $out = [];
        $out = array_merge($out, $this->flattenAcciones($this->client->fetchAcciones(), 'ACCIONES_AR'));
        $out = array_merge($out, $this->flattenBody($this->client->fetchCedears()['body'] ?? [], 'CEDEAR'));
        $out = array_merge($out, $this->flattenBody($this->client->fetchBonos()['body'] ?? [], 'BONO'));
        $out = array_merge($out, $this->flattenAcciones($this->client->fetchMercadosGlobales(), 'MERCADO_GLOBAL'));
        return $out;
    }

    private function findSymbol(array $rows, string $symbol): ?array
    {
        $base = $this->baseSymbol($symbol);
        foreach ($rows as $row) {
            $candidates = [
                $row['simbolo'] ?? null,
                $row['especie'] ?? null,
                $row['symbol'] ?? null,
                $row['ticker'] ?? null,
                $row[''] ?? null,
            ];
            foreach ($candidates as $cand) {
                if ($cand === null) {
                    continue;
                }
                $especie = strtoupper(trim((string) $cand));
                $candBase = $this->baseSymbol($especie);
                if ($especie === $symbol || $candBase === $base) {
                    return $row;
                }
            }
        }
        return null;
    }

    /**
     * @param array<string,mixed> $raw
     * @return array<int,array<string,mixed>>
     */
    private function flattenAcciones(array $raw, string $category): array
    {
        $items = [];
        foreach ($raw as $segment => $rows) {
            if (!is_array($rows)) {
                continue;
            }
            foreach ($rows as $row) {
                $items[] = $this->addCategory((array) $row, $category);
            }
        }
        return $items;
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private function flattenBody(array $rows, string $category): array
    {
        $items = [];
        foreach ($rows as $row) {
            $items[] = $this->addCategory((array) $row, $category);
        }
        return $items;
    }

    private function addCategory(array $row, string $category): array
    {
        $row['category'] = $category;
        return $row;
    }

    private function asOf(array $row): string
    {
        $fecha = $row['fecha'] ?? null;
        $hora = $row['hora'] ?? null;
        if ($fecha) {
            $h = $hora ? (preg_match('/^\\d{2}:\\d{2}$/', $hora) ? $hora . ':00' : $hora) : '00:00:00';
            return $fecha . ' ' . $h;
        }
        return (new \DateTimeImmutable('now', new \DateTimeZone('America/Argentina/Buenos_Aires')))->format('Y-m-d H:i:s');
    }

    private function currencyFor(string $category): string
    {
        return match ($category) {
            'ACCIONES_AR', 'BONO' => 'ARS',
            default => 'USD',
        };
    }

    private function baseSymbol(string $symbol): string
    {
        $s = strtoupper(trim($symbol));
        $s = str_replace(['.', '-', ' '], '', $s);
        return $s;
    }
}
