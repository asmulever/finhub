<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Exception\AccountAccessException;
use App\Application\Account\Exception\AccountNotFoundException;
use App\Application\Account\Exception\AccountValidationException;
use App\Domain\Account;
use App\Domain\Repository\AccountRepositoryInterface;

final class UpdateAccountUseCase
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
    }

    public function execute(int $userId, int $accountId, array $payload): array
    {
        $existing = $this->accountRepository->findById($accountId);
        if ($existing === null) {
            throw new AccountNotFoundException('Account not found.');
        }

        if ($existing->getUserId() !== $userId) {
            throw new AccountAccessException('Unauthorized broker reference.');
        }

        $input = AccountUpdatePayload::fromArray($payload, $existing);
        $updated = new Account(
            $accountId,
            $userId,
            $input->getBrokerName(),
            $input->getCurrency(),
            $input->isPrimary()
        );

        $this->accountRepository->update($updated);

        $detailed = $this->accountRepository->findDetailedById($accountId);
        if ($detailed === null) {
            throw new \RuntimeException('Unable to load account after update.');
        }

        return $detailed;
    }
}
