<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\FinancialObjectRepository;
use App\Domain\Portfolio;
use App\Domain\PortfolioRepository;
use App\Domain\PortfolioTickerRepository;
use App\Infrastructure\Logger;

class PortfolioService
{
    private Logger $logger;

    public function __construct(
        private readonly PortfolioRepository $portfolioRepository,
        private readonly PortfolioTickerRepository $tickerRepository,
        private readonly FinancialObjectRepository $financialObjectRepository
    ) {
        $this->logger = new Logger();
    }

    public function getPortfolioWithTickers(int $userId): array
    {
        $portfolio = $this->getOrCreatePortfolio($userId);
        $tickers = $this->tickerRepository->findDetailedByPortfolio($portfolio->getId(), $userId);

        return [
            'portfolio' => $portfolio->toArray(),
            'tickers' => $tickers,
        ];
    }

    public function addTicker(int $userId, array $payload): array
    {
        $portfolio = $this->getOrCreatePortfolio($userId);
        $financialObjectId = (int)($payload['financial_object_id'] ?? 0);
        $quantity = (float)($payload['quantity'] ?? 0);
        $avgPrice = (float)($payload['avg_price'] ?? 0);

        $this->assertTickerPayload($financialObjectId, $quantity, $avgPrice);

        $financialObject = $this->financialObjectRepository->findById($financialObjectId);
        if ($financialObject === null) {
            throw new \RuntimeException('Financial object not found.');
        }

        $tickerId = $this->tickerRepository->create(
            $portfolio->getId(),
            $financialObjectId,
            $quantity,
            $avgPrice,
            $userId
        );

        $created = $this->tickerRepository->findDetailedById($tickerId, $userId);
        if ($created === null) {
            throw new \RuntimeException('Unable to fetch created ticker.');
        }

        return $created;
    }

    public function updateTicker(int $userId, int $tickerId, array $payload): void
    {
        $quantity = (float)($payload['quantity'] ?? 0);
        $avgPrice = (float)($payload['avg_price'] ?? 0);

        if ($quantity <= 0 || $avgPrice <= 0) {
            throw new \RuntimeException('Invalid ticker payload.');
        }

        $success = $this->tickerRepository->update($tickerId, $quantity, $avgPrice, $userId);
        if (!$success) {
            throw new \RuntimeException('Ticker not found or unauthorized.');
        }
    }

    public function deleteTicker(int $userId, int $tickerId): void
    {
        $success = $this->tickerRepository->delete($tickerId, $userId);
        if (!$success) {
            throw new \RuntimeException('Ticker not found or unauthorized.');
        }
    }

    private function getOrCreatePortfolio(int $userId): Portfolio
    {
        $existing = $this->portfolioRepository->findByUserId($userId);
        if ($existing !== null) {
            return $existing;
        }

        $name = sprintf('Portfolio #%d', $userId);
        $portfolioId = $this->portfolioRepository->createForUser($userId, $name);
        $this->logger->info("Portfolio {$portfolioId} created for user {$userId}");

        return $this->portfolioRepository->findByUserId($userId) ?? new Portfolio($portfolioId, $userId, $name);
    }

    private function assertTickerPayload(int $financialObjectId, float $quantity, float $avgPrice): void
    {
        if ($financialObjectId <= 0) {
            throw new \RuntimeException('financial_object_id is required.');
        }

        if ($quantity <= 0 || $avgPrice <= 0) {
            throw new \RuntimeException('quantity and avg_price must be positive.');
        }
    }
}
