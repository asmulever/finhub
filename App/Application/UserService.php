<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\User;
use App\Infrastructure\Config;
use App\Infrastructure\Logger;

class UserService
{
    private Logger $logger;

    public function __construct(private readonly UserRepositoryInterface $userRepository)
    {
        $this->logger = new Logger();
    }

    public function getAllUsers(): array
    {
        $this->logger->info('Fetching all users.');
        try {
            $users = $this->userRepository->findAll();
            $activeUsers = array_values(
                array_filter(
                    $users,
                    static fn(User $user): bool => $user->isActive()
                )
            );
            return array_map(fn(User $user) => $user->toArray(), $activeUsers);
        } catch (\Exception $e) {
            $this->logger->error('Error fetching users: ' . $e->getMessage());
            return [];
        }
    }

    public function createUser(array $data): ?array
    {
        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $role = trim($data['role'] ?? 'user');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->logger->warning('Validation failed while creating user.');
            return null;
        }

        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $isActive = $this->shouldActivateUser($email);
            $user = new User(null, strtolower($email), $hashed, $role, $isActive);
            $id = $this->userRepository->save($user);

            return [
                'id' => $id,
                'email' => strtolower($email),
                'role' => $role,
            ];
        } catch (\Exception $e) {
            $this->logger->error('Error creating user: ' . $e->getMessage());
            return null;
        }
    }

    public function updateUser(int $id, array $data): bool
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to update non-existing user $id");
            return false;
        }

        $email = trim($data['email'] ?? '');
        $password = $data['password'] ?? '';
        $role = trim($data['role'] ?? $existing->getRole());

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $password === '') {
            $this->logger->warning("Validation failed while updating user $id");
            return false;
        }

        try {
            $hashed = password_hash($password, PASSWORD_DEFAULT);
            $user = new User($id, strtolower($email), $hashed, $role, $existing->isActive());
            $this->userRepository->update($user);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error updating user $id: " . $e->getMessage());
            return false;
        }
    }

    public function deleteUser(int $id): bool
    {
        $existing = $this->userRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to delete non-existing user $id");
            return false;
        }

        try {
            $this->userRepository->delete($id);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error deleting user $id: " . $e->getMessage());
            return false;
        }
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
