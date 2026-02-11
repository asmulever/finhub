<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite\Provider;

use FinHub\Application\Cache\CacheInterface;
use FinHub\Infrastructure\Logging\LoggerInterface;

abstract class AbstractHttpProvider
{
    protected LoggerInterface $logger;
    protected CacheInterface $cache;
    protected int $timeout;

    public function __construct(LoggerInterface $logger, CacheInterface $cache, int $timeout = 8)
    {
        $this->logger = $logger;
        $this->cache = $cache;
        $this->timeout = $timeout;
    }

    protected function getJson(string $url, array $headers = []): array
    {
        $cacheKey = 'r2lite:http:' . md5($url);
        $cached = $this->cache->get($cacheKey, null);
        if (is_array($cached)) {
            return $cached;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new \RuntimeException($err !== '' ? $err : 'Error HTTP');
        }
        if ($status >= 400) {
            throw new \RuntimeException("HTTP $status $url");
        }
        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException("JSON inválido ($url)");
        }
        $this->cache->set($cacheKey, $decoded, 60);
        return $decoded;
    }

    protected function csv(string $url): array
    {
        $cacheKey = 'r2lite:csv:' . md5($url);
        $cached = $this->cache->get($cacheKey, null);
        if (is_array($cached)) {
            return $cached;
        }
        $opts = [
            'http' => [
                'method' => 'GET',
                'timeout' => $this->timeout,
                'header' => "Accept: text/csv\r\n",
            ],
        ];
        $body = @file_get_contents($url, false, stream_context_create($opts));
        if ($body === false) {
            throw new \RuntimeException("No se pudo leer CSV $url");
        }
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $body);
        rewind($fh);
        $header = fgetcsv($fh);
        while (($line = fgetcsv($fh)) !== false) {
            $rows[] = array_combine($header, $line);
        }
        fclose($fh);
        $this->cache->set($cacheKey, $rows, 300);
        return $rows;
    }
}
