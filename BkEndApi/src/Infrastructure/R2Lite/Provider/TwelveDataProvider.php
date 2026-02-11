<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite\Provider;

use FinHub\Application\R2Lite\ProviderInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Cache\CacheInterface;

final class TwelveDataProvider extends AbstractHttpProvider implements ProviderInterface
{
    private string $apiKey;

    public function __construct(Config $config, LoggerInterface $logger, CacheInterface $cache)
    {
        parent::__construct($logger, $cache, (int) $config->get('TWELVEDATA_TIMEOUT_SECONDS', 8));
        $this->apiKey = (string) $config->get('TWELVEDATA_API_KEY', '');
    }

    public function name(): string
    {
        return 'twelvedata';
    }

    public function fetchDaily(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to, string $category): array
    {
        if ($this->apiKey === '') {
            return [];
        }
        $url = sprintf(
            'https://api.twelvedata.com/time_series?symbol=%s&interval=1day&start_date=%s&end_date=%s&apikey=%s&format=JSON',
            urlencode($symbol),
            $from->format('Y-m-d'),
            $to->format('Y-m-d'),
            $this->apiKey
        );
        $resp = $this->getJson($url);
        $values = $resp['values'] ?? [];
        if (!is_array($values)) {
            return [];
        }
        $out = [];
        foreach ($values as $row) {
            $out[] = [
                'symbol' => $symbol,
                'as_of' => $row['datetime'] ?? null,
                'open' => isset($row['open']) ? (float) $row['open'] : null,
                'high' => isset($row['high']) ? (float) $row['high'] : null,
                'low' => isset($row['low']) ? (float) $row['low'] : null,
                'close' => isset($row['close']) ? (float) $row['close'] : null,
                'volume' => isset($row['volume']) ? (float) $row['volume'] : null,
                'currency' => $resp['meta']['currency'] ?? null,
                'provider' => $this->name(),
            ];
        }
        return $out;
    }
}
