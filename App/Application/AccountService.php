<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Account;
use App\Domain\Repository\AccountRepositoryInterface;
use App\Domain\Repository\UserRepositoryInterface;
use App\Infrastructure\Logger;

class AccountService
{
    private Logger $logger;

    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
        private readonly UserRepositoryInterface $userRepository,
    ) {
        $this->logger = new Logger();
    }

    public function listAccounts(int $userId): array
    {
        return $this->accountRepository->findDetailed($userId);
    }

    public function createAccount(int $userId, array $data): ?array
    {
        $brokerName = trim($data['broker_name'] ?? '');
        $currency = strtoupper(trim($data['currency'] ?? 'USD'));
        $isPrimary = isset($data['is_primary']) ? (bool)$data['is_primary'] : false;

        if ($brokerName === '' || strlen($currency) === 0) {
            $this->logger->warning('Invalid data when creating account.');
            return null;
        }

        $account = new Account(null, $userId, $brokerName, $currency, $isPrimary);
        $accountId = $this->accountRepository->save($account);

        return $this->accountRepository->findDetailedById($accountId);
    }

    public function updateAccount(int $userId, int $id, array $data): ?array
    {
        $existing = $this->accountRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to update missing account {$id}");
            return null;
        }
        if ($existing->getUserId() !== $userId) {
            $this->logger->warning("User {$userId} attempted to update account {$id} not owned by them.");
            return null;
        }

        $targetUserId = $existing->getUserId();
        $brokerName = trim($data['broker_name'] ?? $existing->getBrokerName());
        $currency = strtoupper(trim($data['currency'] ?? $existing->getCurrency()));
        $isPrimary = isset($data['is_primary']) ? (bool)$data['is_primary'] : $existing->isPrimary();

        if ($brokerName === '' || $currency === '' || $targetUserId <= 0) {
            $this->logger->warning("Invalid data updating account {$id}");
            return null;
        }

        $updated = new Account($id, $targetUserId, $brokerName, $currency, $isPrimary);
        $this->accountRepository->update($updated);

        return $this->accountRepository->findDetailedById($id);
    }

    public function deleteAccount(int $userId, int $id): bool
    {
        $existing = $this->accountRepository->findById($id);
        if ($existing === null || $existing->getUserId() !== $userId) {
            return false;
        }

        $this->accountRepository->delete($id);
        return true;
    }
}
