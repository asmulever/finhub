<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\LogService;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;

final class ListUsersUseCase
{
    private LogService $logger;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
        $this->logger = LogService::getInstance();
    }

    /**
     * @return array{ id: int, email: string, role: string, is_active: bool }[]
     */
    public function execute(): array
    {
        $this->logger->info('Listing users.');
        try {
            $users = $this->userRepository->findAll();
            $activeUsers = array_filter($users, static fn(User $user) => $user->isActive());
            return array_map(fn(User $user) => $user->toArray(), array_values($activeUsers));
        } catch (\Throwable $e) {
            $this->logger->error('Failed to list users: ' . $e->getMessage());
            return [];
        }
    }
}
