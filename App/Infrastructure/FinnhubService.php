<?php

declare(strict_types=1);

namespace App\Infrastructure;

class FinnhubService
{
    private const BASE_URL = 'https://finnhub.io/api/v1';
    private const ETF_SYMBOLS = ['SPY', 'QQQ', 'DIA', 'IWM', 'ARKK'];
    private const INDEX_SYMBOLS = [
        ['symbol' => '^GSPC', 'description' => 'S&P 500'],
        ['symbol' => '^NDX', 'description' => 'NASDAQ 100'],
        ['symbol' => '^DJI', 'description' => 'Dow Jones Industrial'],
        ['symbol' => '^RUT', 'description' => 'Russell 2000'],
        ['symbol' => '^VIX', 'description' => 'Volatility Index'],
    ];

    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $secret = null
    ) {
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function getSymbols(string $category): array
    {
        $normalized = strtolower($category);
        return match ($normalized) {
            'stocks' => $this->request('/stock/symbol', ['exchange' => 'US']),
            'forex' => $this->request('/forex/symbol'),
            'crypto' => $this->request('/crypto/symbol', ['exchange' => 'binance']),
            'etfs' => $this->fetchStaticProfiles(self::ETF_SYMBOLS),
            'indices' => self::INDEX_SYMBOLS,
            default => throw new \InvalidArgumentException("Unsupported category: {$category}"),
        };
    }

    /**
     * @return array<string,mixed>
     */
    public function getQuote(string $symbol): array
    {
        return $this->request('/quote', ['symbol' => $symbol]);
    }

    /**
     * @param array<int,string> $symbols
     * @return array<int,array<string,mixed>>
     */
    private function fetchStaticProfiles(array $symbols): array
    {
        $result = [];
        foreach ($symbols as $symbol) {
            try {
                $profile = $this->request('/etf/profile', ['symbol' => $symbol]);
                if (!is_array($profile) || empty($profile)) {
                    continue;
                }

                $result[] = [
                    'symbol' => $symbol,
                    'description' => $profile['profile']['name'] ?? ($profile['name'] ?? $symbol),
                ];
            } catch (\Throwable) {
                // ignore failed symbol to keep flow resilient
            }
        }

        return $result;
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
