<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\UserRepository;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class AuthService
{
    private Logger $logger;

    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JwtService $jwtService
    ) {
        $this->logger = new Logger();
    }

    public function validateCredentials(string $email, string $password): ?string
    {
        $this->logger->info("Login attempt for email: $email");
        $user = $this->userRepository->findByEmail($email);

        if ($user && password_verify($password, $user->getPasswordHash())) {
            $this->logger->info("Login successful for email: $email");
            return $this->jwtService->generateToken([
                'uid' => $user->getId(),
                'email' => $user->getEmail(),
                'role' => $user->getRole(),
            ]);
        }

        $this->logger->warning("Failed login attempt for email: $email");
        return null;
    }

    public function validateToken(string $token): bool
    {
        $payload = $this->jwtService->validateToken($token);
        return $payload !== null;
    }

    public function decodeToken(string $token): ?object
    {
        return $this->jwtService->validateToken($token);
    }
}
