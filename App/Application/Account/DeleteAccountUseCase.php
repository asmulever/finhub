<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Exception\AccountAccessException;
use App\Application\Account\Exception\AccountNotFoundException;
use App\Domain\Repository\AccountRepositoryInterface;

final class DeleteAccountUseCase
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
    }

    public function execute(int $userId, int $accountId): void
    {
        $existing = $this->accountRepository->findById($accountId);
        if ($existing === null) {
            throw new AccountNotFoundException('Account not found.');
        }

        if ($existing->getUserId() !== $userId) {
            throw new AccountAccessException('Unauthorized broker reference.');
        }

        $this->accountRepository->delete($accountId);
    }
}
