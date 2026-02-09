<?php
declare(strict_types=1);

namespace FinHub\Application\Portfolio;

/**
 * Casos de uso para gestionar portafolios e instrumentos.
 */
final class PortfolioService
{
    private PortfolioRepositoryInterface $repository;

    public function __construct(PortfolioRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    public function listInstruments(int $userId): array
    {
        $portfolioId = $this->repository->ensureUserPortfolio($userId);
        return $this->repository->listInstruments($portfolioId);
    }

    public function addInstrument(int $userId, array $payload): array
    {
        $portfolioId = $this->repository->ensureUserPortfolio($userId);
        return $this->repository->addInstrument($portfolioId, $payload);
    }

    public function removeInstrument(int $userId, string $symbol): bool
    {
        $portfolioId = $this->repository->ensureUserPortfolio($userId);
        return $this->repository->removeInstrument($portfolioId, $symbol);
    }

    public function listPortfolios(int $userId): array
    {
        $this->repository->ensureUserPortfolio($userId);
        return $this->repository->listPortfolios($userId);
    }

    public function getBaseCurrency(int $userId): string
    {
        $portfolioId = $this->repository->ensureUserPortfolio($userId);
        return $this->repository->getBaseCurrency($portfolioId);
    }

    public function listSymbols(?int $userId = null): array
    {
        $symbols = $this->repository->listSymbols($userId);
        $clean = [];
        $seen = [];
        foreach ($symbols as $symbol) {
            $sym = $this->baseSymbol((string) $symbol);
            if ($sym === '' || isset($seen[$sym])) {
                continue;
            }
            $seen[$sym] = true;
            $clean[] = $sym;
        }
        return $clean;
    }

    private function baseSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));
        if ($symbol === '') {
            return '';
        }
        if (strpos($symbol, '-') === false) {
            return $symbol;
        }
        $parts = explode('-', $symbol);
        $left = $parts[0] ?? '';
        if ($left === '') {
            return $symbol;
        }
        // Si hay más de un guion o el sufijo contiene números, cortar a base.
        $suffix = implode('-', array_slice($parts, 1));
        if ($suffix === '') {
            return $symbol;
        }
        if (preg_match('/\\d/', $suffix) === 1 || count($parts) > 2) {
            return $left;
        }
        // Mantener clases tipo BRK-B (sufijo 1 letra).
        if (strlen($suffix) === 1 && ctype_alpha($suffix)) {
            return $symbol;
        }
        // Sufijos cortos de letras (ej. AAPL-BA) -> base.
        if (strlen($suffix) >= 2 && strlen($suffix) <= 4 && ctype_alpha($suffix)) {
            return $left;
        }
        return $symbol;
    }
}
