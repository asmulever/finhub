<?php
declare(strict_types=1);

namespace FinHub\Application\MarketData\Dto;

final class PriceRequest
{
    private string $symbol;

    public function __construct(string $symbol)
    {
        $normalized = strtoupper(trim($symbol));
        if ($normalized === '') {
            throw new \RuntimeException('El símbolo es requerido', 400);
        }
        $this->symbol = $normalized;
    }

    public static function fromArray(array $input): self
    {
        $symbol = (string) ($input['symbol'] ?? '');
        return new self($symbol);
    }

    public function getSymbol(): string
    {
        return $this->symbol;
    }
}
