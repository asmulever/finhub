<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite\Provider;

use FinHub\Application\R2Lite\ProviderInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Cache\CacheInterface;

final class AlphaVantageProvider extends AbstractHttpProvider implements ProviderInterface
{
    private string $apiKey;

    public function __construct(Config $config, LoggerInterface $logger, CacheInterface $cache)
    {
        parent::__construct($logger, $cache, (int) $config->get('ALPHAVANTAGE_TIMEOUT_SECONDS', 8));
        $this->apiKey = (string) $config->get('ALPHAVANTAGE_API_KEY', '');
    }

    public function name(): string
    {
        return 'alphavantage';
    }

    public function fetchDaily(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to, string $category): array
    {
        if ($this->apiKey === '') {
            return [];
        }
        $url = sprintf(
            'https://www.alphavantage.co/query?function=TIME_SERIES_DAILY&symbol=%s&outputsize=compact&apikey=%s',
            urlencode($symbol),
            $this->apiKey
        );
        $resp = $this->getJson($url);
        $ts = $resp['Time Series (Daily)'] ?? [];
        if (!is_array($ts)) {
            return [];
        }
        $out = [];
        foreach ($ts as $date => $row) {
            $asOf = new \DateTimeImmutable($date);
            if ($asOf < $from || $asOf > $to) {
                continue;
            }
            $out[] = [
                'symbol' => $symbol,
                'as_of' => $date,
                'open' => isset($row['1. open']) ? (float) $row['1. open'] : null,
                'high' => isset($row['2. high']) ? (float) $row['2. high'] : null,
                'low' => isset($row['3. low']) ? (float) $row['3. low'] : null,
                'close' => isset($row['4. close']) ? (float) $row['4. close'] : null,
                'volume' => isset($row['5. volume']) ? (float) $row['5. volume'] : null,
                'currency' => $category === 'ACCIONES_AR' ? 'ARS' : 'USD',
                'provider' => $this->name(),
            ];
        }
        return $out;
    }
}
