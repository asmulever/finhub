<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData\Dto;

final class StockItem
{
    public string $symbol;
    public ?string $name;
    public ?string $currency;
    public ?string $exchange;
    public ?string $country;
    public ?string $micCode;

    public function __construct(
        string $symbol,
        ?string $name,
        ?string $currency,
        ?string $exchange,
        ?string $country,
        ?string $micCode
    ) {
        $this->symbol = $symbol;
        $this->name = $name;
        $this->currency = $currency;
        $this->exchange = $exchange;
        $this->country = $country;
        $this->micCode = $micCode;
    }

    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['symbol'] ?? ''),
            $data['name'] ?? null,
            $data['currency'] ?? null,
            $data['exchange'] ?? null,
            $data['country'] ?? null,
            $data['mic_code'] ?? null
        );
    }

    public function toArray(): array
    {
        return [
            'symbol' => $this->symbol,
            'name' => $this->name,
            'currency' => $this->currency,
            'exchange' => $this->exchange,
            'country' => $this->country,
            'mic_code' => $this->micCode,
        ];
    }
}
