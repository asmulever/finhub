<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Account;
use App\Domain\Repository\AccountRepositoryInterface;

final class CreateAccountUseCase
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository,
    ) {
    }

    public function execute(int $userId, array $payload): array
    {
        $input = AccountCreationPayload::fromArray($payload);
        $account = new Account(
            null,
            $userId,
            $input->getBrokerName(),
            $input->getCurrency(),
            $input->isPrimary()
        );

        $accountId = $this->accountRepository->save($account);

        $detailed = $this->accountRepository->findDetailedById($accountId);
        if ($detailed === null) {
            throw new \RuntimeException('Unable to load account after creation.');
        }

        return $detailed;
    }
}
