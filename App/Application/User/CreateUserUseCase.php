<?php

declare(strict_types=1);

namespace App\Application\User;

use App\Application\LogService;
use App\Application\User\Exception\UserValidationException;
use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;
use App\Infrastructure\Config;

final class CreateUserUseCase
{
    private LogService $logger;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository
    ) {
        $this->logger = LogService::getInstance();
    }

    public function execute(array $payload): array
    {
        $input = UserCreationPayload::fromArray($payload);
        $this->logger->info('Creating user for ' . $input->getEmail());

        $isActive = $this->shouldActivateUser($input->getEmail());
        $passwordHash = password_hash($input->getPassword(), PASSWORD_DEFAULT);

        if ($passwordHash === false) {
            throw new UserValidationException('Unable to hash password.');
        }

        $user = new User(
            null,
            $input->getEmail(),
            $passwordHash,
            $input->getRole(),
            $isActive
        );

        $id = $this->userRepository->save($user);

        return [
            'id' => $id,
            'email' => $input->getEmail(),
            'role' => $input->getRole(),
        ];
    }

    private function shouldActivateUser(string $email): bool
    {
        $normalized = strtolower($email);
        $rootEmail = strtolower(Config::get('ROOT_EMAIL', 'root@example.com'));
        if ($normalized !== $rootEmail) {
            return true;
        }

        return Config::get('ENABLE_ROOT_USER', '0') === '1';
    }
}
