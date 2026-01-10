<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

interface PortfolioRepositoryInterface
{
    public function ensureUserPortfolio(int $userId): int;

    public function listInstruments(int $portfolioId): array;

    public function addInstrument(int $portfolioId, array $payload): array;

    public function removeInstrument(int $portfolioId, string $symbol): bool;

    /**
     * Lista símbolos del/los portafolios. Si se indica userId, filtra por ese usuario.
     *
     * @param int|null $userId
     * @return array<int,string>
     */
    public function listSymbols(?int $userId = null): array;

    public function listPortfolios(int $userId): array;

    public function getBaseCurrency(int $portfolioId): string;
}
