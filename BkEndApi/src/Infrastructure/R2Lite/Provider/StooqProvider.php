<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite\Provider;

use FinHub\Application\R2Lite\ProviderInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Application\Cache\CacheInterface;

final class StooqProvider extends AbstractHttpProvider implements ProviderInterface
{
    private string $base;

    public function __construct(Config $config, LoggerInterface $logger, CacheInterface $cache)
    {
        parent::__construct($logger, $cache, (int) $config->get('STOOQ_TIMEOUT_SECONDS', 8));
        $this->base = rtrim((string) $config->get('STOOQ_BASE_URL', 'https://stooq.pl'), '/');
    }

    public function name(): string
    {
        return 'stooq';
    }

    public function fetchDaily(string $symbol, \DateTimeImmutable $from, \DateTimeImmutable $to, string $category): array
    {
        $url = sprintf('%s/db/h/s%s.csv', $this->base, strtolower($symbol));
        $rows = $this->csv($url);
        $out = [];
        foreach ($rows as $row) {
            $date = $row['Date'] ?? null;
            if (!$date) {
                continue;
            }
            $asOf = new \DateTimeImmutable($date);
            if ($asOf < $from || $asOf > $to) {
                continue;
            }
            $out[] = [
                'symbol' => $symbol,
                'as_of' => $date,
                'open' => isset($row['Open']) ? (float) $row['Open'] : null,
                'high' => isset($row['High']) ? (float) $row['High'] : null,
                'low' => isset($row['Low']) ? (float) $row['Low'] : null,
                'close' => isset($row['Close']) ? (float) $row['Close'] : null,
                'volume' => isset($row['Volume']) ? (float) $row['Volume'] : null,
                'currency' => 'USD',
                'provider' => $this->name(),
            ];
        }
        return $out;
    }
}
