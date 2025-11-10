<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\UserRepository;
use App\Infrastructure\JwtService;

class AuthService
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly JwtService $jwtService
    ) {
    }

    public function validateCredentials(string $email, string $password): ?string
    {
        $user = $this->userRepository->findByEmail($email);

        if ($user === null || !$user->verifyPassword($password)) {
            return null;
        }

        return $this->jwtService->generateToken(['uid' => $user->getId()]);
    }

    public function validateToken(string $token): bool
    {
        $payload = $this->jwtService->validateToken($token);
        return $payload !== null;
    }
}
