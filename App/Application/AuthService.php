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

    public function validateCredentials(string $email, string $password): ?array
    {
        $this->logger->info("Login attempt for email: $email");
        $user = $this->userRepository->findByEmail($email);

        if ($user && $user->isActive() && password_verify($password, $user->getPasswordHash())) {
            $this->logger->info("Login successful for email: $email");
            return $this->issueTokensForUser($user);
        }

        $this->logger->warning("Failed login attempt for email: $email");
        return null;
    }

    public function validateToken(string $token): bool
    {
        $payload = $this->jwtService->validateToken($token, 'access');
        return $payload !== null;
    }

    public function decodeToken(string $token): ?object
    {
        return $this->jwtService->validateToken($token, 'access');
    }

    public function refreshTokens(string $refreshToken): ?array
    {
        $payload = $this->jwtService->validateToken($refreshToken, 'refresh');
        if ($payload === null) {
            return null;
        }

        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        if ($userId === null) {
            return null;
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null || !$user->isActive()) {
            return null;
        }

        return $this->issueTokensForUser($user);
    }

    private function issueTokensForUser(\App\Domain\User $user): array
    {
        $payload = [
            'uid' => $user->getId(),
            'email' => $user->getEmail(),
            'role' => $user->getRole(),
        ];

        $accessToken = $this->jwtService->generateAccessToken($payload, 300); // 5 minutes
        $refreshToken = $this->jwtService->generateRefreshToken(['uid' => $user->getId()], 604800); // 7 days

        $decodedAccess = $this->jwtService->validateToken($accessToken, 'access');
        $accessExp = $decodedAccess->exp ?? (time() + 300);

        $decodedRefresh = $this->jwtService->validateToken($refreshToken, 'refresh');
        $refreshExp = $decodedRefresh->exp ?? (time() + 604800);

        return [
            'payload' => $payload + ['exp' => $accessExp],
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_expires_at' => (int)$accessExp,
            'refresh_expires_at' => (int)$refreshExp,
        ];
    }
}
