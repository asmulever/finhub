<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\FinancialObjectRepositoryInterface;
use App\Domain\Repository\PortfolioTickerRepositoryInterface;
use App\Infrastructure\Logger;

class PortfolioService
{
    private Logger $logger;

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly PortfolioTickerRepositoryInterface $tickerRepository,
        private readonly FinancialObjectRepositoryInterface $financialObjectRepository
    ) {
        $this->logger = new Logger();
    }

    public function getTickersForBroker(int $userId, int $brokerId): array
    {
        $this->assertBrokerOwnership($userId, $brokerId);
        return $this->tickerRepository->findDetailedByBroker($brokerId, $userId);
    }

    public function addTicker(int $userId, array $payload): array
    {
        $brokerId = (int)($payload['broker_id'] ?? 0);
        $financialObjectId = (int)($payload['financial_object_id'] ?? 0);
        $quantity = (float)($payload['quantity'] ?? 0);
        $avgPrice = (float)($payload['avg_price'] ?? 0);

        $this->assertTickerPayload($brokerId, $financialObjectId, $quantity, $avgPrice);
        $this->assertBrokerOwnership($userId, $brokerId);

        $financialObject = $this->financialObjectRepository->findById($financialObjectId);
        if ($financialObject === null) {
            throw new \RuntimeException('Financial object not found.');
        }

        $tickerId = $this->tickerRepository->create($brokerId, $financialObjectId, $quantity, $avgPrice, $userId);

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

    private function assertBrokerOwnership(int $userId, int $brokerId): void
    {
        $broker = $this->accountRepository->findById($brokerId);
        if ($broker === null || $broker->getUserId() !== $userId) {
            throw new \RuntimeException('Unauthorized broker reference.');
        }
    }

    private function assertTickerPayload(int $brokerId, int $financialObjectId, float $quantity, float $avgPrice): void
    {
        if ($brokerId <= 0) {
            throw new \RuntimeException('broker_id is required.');
        }

        if ($financialObjectId <= 0) {
            throw new \RuntimeException('financial_object_id is required.');
        }

        if ($quantity <= 0 || $avgPrice <= 0) {
            throw new \RuntimeException('quantity and avg_price must be positive.');
        }
    }
}
