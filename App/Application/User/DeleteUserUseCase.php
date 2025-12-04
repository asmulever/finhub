<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\LogService;
use App\Application\User\Exception\UserNotFoundException;
use App\Domain\Repository\UserRepositoryInterface;

final class DeleteUserUseCase
{
    private LogService $logger;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
        $this->logger = LogService::getInstance();
    }

    public function execute(int $id): void
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            throw new UserNotFoundException("Usuario {$id} no encontrado.");
        }

        $this->userRepository->delete($id);
        $this->logger->info("Usuario {$id} eliminado.");
    }
}
