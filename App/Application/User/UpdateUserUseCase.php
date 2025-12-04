<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\LogService;
use App\Application\User\Exception\UserNotFoundException;
use App\Application\User\Exception\UserValidationException;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;

final class UpdateUserUseCase
{
    private LogService $logger;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
        $this->logger = LogService::getInstance();
    }

    public function execute(int $id, array $payload): void
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            throw new UserNotFoundException("Usuario {$id} no encontrado.");
        }

        $input = UserUpdatePayload::fromArray($payload, $existing);
        $passwordHash = password_hash($input->getPassword(), PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new UserValidationException('Unable to hash password.');
        }

        $user = new User(
            $id,
            $input->getEmail(),
            $passwordHash,
            $input->getRole(),
            $existing->isActive()
        );

        $this->userRepository->update($user);
    }
}
