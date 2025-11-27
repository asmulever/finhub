<?php

declare(strict_types=1);

namespace App\Application;

use App\Infrastructure\FinnhubHttpClient;
use DateTimeImmutable;
use DateTimeInterface;

class FinnhubPriceDataSource implements PriceDataSourceInterface
{
    public function __construct(
        private readonly FinnhubHttpClient $client
    ) {
    }

    public function getSourceName(): string
    {
        return 'FINHUB';
    }

    public function fetchDailyBars(string $sourceSymbol, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $raw = $this->client->getDailyCandles($sourceSymbol, $from, $to);

        // Finnhub candles: { s: 'ok'|'no_data', t:[], o:[], h:[], l:[], c:[], v:[] }
        if (!is_array($raw) || ($raw['s'] ?? null) !== 'ok') {
            return [];
        }

        $timestamps = $raw['t'] ?? [];
        $opens = $raw['o'] ?? [];
        $highs = $raw['h'] ?? [];
        $lows = $raw['l'] ?? [];
        $closes = $raw['c'] ?? [];
        $volumes = $raw['v'] ?? [];

        $count = is_countable($timestamps) ? count($timestamps) : 0;
        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $ts = $timestamps[$i] ?? null;
            if (!is_int($ts) && !is_float($ts)) {
                continue;
            }

            $date = (new DateTimeImmutable('@' . (int)$ts))->setTimezone(new \DateTimeZone('UTC'));

            $bar = new PriceBarDTO(
                $this->getSourceName(),
                $sourceSymbol,
                $date,
                isset($opens[$i]) ? (float)$opens[$i] : null,
                isset($highs[$i]) ? (float)$highs[$i] : null,
                isset($lows[$i]) ? (float)$lows[$i] : null,
                isset($closes[$i]) ? (float)$closes[$i] : null,
                isset($volumes[$i]) ? (int)$volumes[$i] : null,
                json_encode([
                    't' => $ts,
                    'o' => $opens[$i] ?? null,
                    'h' => $highs[$i] ?? null,
                    'l' => $lows[$i] ?? null,
                    'c' => $closes[$i] ?? null,
                    'v' => $volumes[$i] ?? null,
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            );

            $result[] = $bar;
        }

        return $result;
    }
}

