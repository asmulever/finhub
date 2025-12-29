<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\MarketData;

/**
 * Unifica símbolos provenientes de EODHD y Twelve Data para un exchange.
 */
final class QuoteSymbolsAggregator
{
    private EodhdClient $eodhd;
    private ?TwelveDataClient $twelve;
    private QuoteCache $cache;

    public function __construct(EodhdClient $eodhd, ?TwelveDataClient $twelve, QuoteCache $cache)
    {
        $this->eodhd = $eodhd;
        $this->twelve = $twelve;
        $this->cache = $cache;
    }

    /**
     * Devuelve lista unificada con flags de presencia en cada proveedor.
     *
     * @return array<int,array{symbol:string,name:?string,exchange:?string,currency:?string,type:?string,mic_code:?string,in_eodhd:bool,in_twelvedata:bool}>
     */
    public function listSymbols(string $exchange): array
    {
        $exchange = strtoupper(trim($exchange));
        if ($exchange === '') {
            throw new \RuntimeException('Exchange requerido', 422);
        }
        $cacheKey = sprintf('symbols|%s', $exchange);
        $cached = $this->cache->get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $eodSymbols = [];
        try {
            $eodSymbols = $this->eodhd->fetchExchangeSymbols($exchange);
        } catch (\Throwable $e) {
            // Si falla EODHD, seguimos con TwelveData.
        }

        $twSymbols = [];
        if ($this->twelve !== null) {
            try {
                $twSymbols = $this->twelve->listStocks($exchange);
            } catch (\Throwable $e) {
                // ignorar fallo
            }
        }

        $map = [];

        foreach ($eodSymbols as $item) {
            $symbol = strtoupper((string) ($item['Code'] ?? $item['code'] ?? ''));
            if ($symbol === '') continue;
            $map[$symbol] = [
                'symbol' => $symbol,
                'name' => $item['Name'] ?? $item['name'] ?? null,
                'exchange' => $item['Exchange'] ?? $item['exchange'] ?? $exchange,
                'currency' => $item['Currency'] ?? $item['currency'] ?? null,
                'type' => $item['Type'] ?? $item['type'] ?? null,
                'mic_code' => $item['OperatingMIC'] ?? $item['mic_code'] ?? null,
                'in_eodhd' => true,
                'in_twelvedata' => false,
            ];
        }

        foreach ($twSymbols as $item) {
            $symbol = strtoupper((string) ($item['symbol'] ?? ''));
            if ($symbol === '') continue;
            if (!isset($map[$symbol])) {
                $map[$symbol] = [
                    'symbol' => $symbol,
                    'name' => $item['name'] ?? null,
                    'exchange' => $item['exchange'] ?? $exchange,
                    'currency' => $item['currency'] ?? null,
                    'type' => $item['type'] ?? null,
                    'mic_code' => $item['mic_code'] ?? null,
                    'in_eodhd' => false,
                    'in_twelvedata' => true,
                ];
            } else {
                $map[$symbol]['in_twelvedata'] = true;
                $map[$symbol]['name'] = $map[$symbol]['name'] ?? $item['name'] ?? null;
                $map[$symbol]['currency'] = $map[$symbol]['currency'] ?? $item['currency'] ?? null;
                $map[$symbol]['type'] = $map[$symbol]['type'] ?? $item['type'] ?? null;
                $map[$symbol]['mic_code'] = $map[$symbol]['mic_code'] ?? $item['mic_code'] ?? null;
            }
        }

        $result = array_values($map);
        $this->cache->set($cacheKey, $result, 86400);
        return $result;
    }
}
