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

    public function listSymbols(): array
    {
        return $this->repository->listSymbols();
    }
}
