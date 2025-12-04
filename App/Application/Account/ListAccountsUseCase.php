<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Domain\Repository\AccountRepositoryInterface;

final class ListAccountsUseCase
{
    public function __construct(
        private readonly AccountRepositoryInterface $accountRepository
    ) {
    }

    public function execute(int $userId): array
    {
        return $this->accountRepository->findDetailed($userId);
    }
}
