<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Account;
use App\Domain\AccountRepository;
use App\Domain\PortfolioRepository;
use App\Domain\UserRepository;
use App\Infrastructure\Logger;

class AccountService
{
    private Logger $logger;

    public function __construct(
        private readonly AccountRepository $accountRepository,
        private readonly UserRepository $userRepository,
        private readonly PortfolioRepository $portfolioRepository,
    ) {
        $this->logger = new Logger();
    }

    public function listAccounts(bool $isAdmin, ?int $userId): array
    {
        if ($isAdmin) {
            return $this->accountRepository->findDetailed();
        }

        if ($userId === null) {
            return [];
        }

        return $this->accountRepository->findDetailed($userId);
    }

    public function createAccount(array $data): ?array
    {
        $userId = isset($data['user_id']) ? (int)$data['user_id'] : null;
        $brokerName = trim($data['broker_name'] ?? '');
        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $isPrimary = isset($data['is_primary']) ? (bool)$data['is_primary'] : false;

        if ($userId === null || $brokerName === '' || strlen($currency) === 0) {
            $this->logger->warning('Invalid data when creating account.');
            return null;
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null) {
            $this->logger->warning("Cannot create account, user {$userId} not found.");
            return null;
        }

        $account = new Account(null, $userId, $brokerName, $currency, $isPrimary);
        $accountId = $this->accountRepository->save($account);

        $existingPortfolio = $this->portfolioRepository->findByAccountId($accountId);
        if ($existingPortfolio === null) {
            $this->portfolioRepository->createForAccount($accountId, "{$brokerName} portfolio");
        }

        return $this->accountRepository->findDetailedById($accountId);
    }

    public function updateAccount(int $id, array $data): ?array
    {
        $existing = $this->accountRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to update missing account {$id}");
            return null;
        }

        $userId = isset($data['user_id']) ? (int)$data['user_id'] : $existing->getUserId();
        $brokerName = trim($data['broker_name'] ?? $existing->getBrokerName());
        $currency = strtoupper(trim($data['currency'] ?? $existing->getCurrency()));
        $isPrimary = isset($data['is_primary']) ? (bool)$data['is_primary'] : $existing->isPrimary();

        if ($brokerName === '' || $currency === '' || $userId <= 0) {
            $this->logger->warning("Invalid data updating account {$id}");
            return null;
        }

        if ($userId !== $existing->getUserId()) {
            $user = $this->userRepository->findById($userId);
            if ($user === null) {
                $this->logger->warning("Cannot update account {$id}, user {$userId} not found.");
                return null;
            }
        }

        $updated = new Account($id, $userId, $brokerName, $currency, $isPrimary);
        $this->accountRepository->update($updated);

        return $this->accountRepository->findDetailedById($id);
    }

    public function deleteAccount(int $id): bool
    {
        $existing = $this->accountRepository->findById($id);
        if ($existing === null) {
            return false;
        }

        $this->portfolioRepository->deleteByAccount($id);
        $this->accountRepository->delete($id);
        return true;
    }
}
