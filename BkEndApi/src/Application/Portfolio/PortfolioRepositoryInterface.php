<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

interface PortfolioRepositoryInterface
{
    public function ensureUserPortfolio(int $userId): int;

    public function listInstruments(int $portfolioId): array;

    public function addInstrument(int $portfolioId, array $payload): array;

    public function removeInstrument(int $portfolioId, string $symbol): bool;

    public function listPortfolios(int $userId): array;

    public function listSymbols(): array;

    public function getBaseCurrency(int $portfolioId): string;
}
