<?php

declare(strict_types=1);

namespace App\Infrastructure;

use DateTimeInterface;

class FinnhubHttpClient
{
    private const BASE_URL = 'https://finnhub.io/api/v1';

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $secret = null
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function getDailyCandles(string $symbol, DateTimeInterface $from, DateTimeInterface $to): array
    {
        $fromTs = $from->getTimestamp();
        $toTs = $to->getTimestamp();

        $response = $this->request('/stock/candle', [
            'symbol' => $symbol,
            'resolution' => 'D',
            'from' => $fromTs,
            'to' => $toTs,
        ]);

        return $response;
    }

    /**
     * @param array<string,mixed> $query
     * @return array<mixed>
     */
    private function request(string $endpoint, array $query = []): array
    {
        $query['token'] = $this->apiKey;
        $url = self::BASE_URL . $endpoint . '?' . http_build_query($query);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_FAILONERROR => false,
        ]);

        $headers = [];
        if ($this->secret !== null && $this->secret !== '') {
            $headers[] = 'X-Finnhub-Secret: ' . $this->secret;
        }

        if (!empty($headers)) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        }

        $response = curl_exec($ch);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException('Finnhub request failed: ' . $error);
        }

        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from Finnhub');
        }

        if ($status >= 400) {
            $message = $decoded['error'] ?? $decoded['message'] ?? 'Unknown error';
            throw new \RuntimeException("Finnhub API error ({$status}): {$message}");
        }

        return $decoded;
    }
}

