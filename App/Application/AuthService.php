<?php

declare(strict_types=1);

namespace App\Application;

use App\Application\Auth\Exception\InvalidCredentialsException;
use App\Application\Auth\Exception\InvalidRefreshTokenException;
use App\Application\Auth\TokenServiceInterface;
use App\Application\LogService;
use App\Domain\Repository\UserRepositoryInterface;

class AuthService
{
    private LogService $logger;
    private int $sessionTimeoutSeconds;
    private int $refreshTokenTtlSeconds;

    public function __construct(
        private readonly UserRepositoryInterface $userRepository,
        private readonly TokenServiceInterface $tokenService,
        int $sessionTimeoutSeconds,
        int $refreshTokenTtlSeconds
    ) {
        $this->logger = LogService::getInstance();
        if ($sessionTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('SESSION_TIMEOUT_MS must resolve to at least 1 second.');
        }
        if ($refreshTokenTtlSeconds < 1) {
            throw new \InvalidArgumentException('Refresh token TTL must be a positive integer.');
        }

        $this->sessionTimeoutSeconds = $sessionTimeoutSeconds;
        $this->refreshTokenTtlSeconds = $refreshTokenTtlSeconds;
    }

    public function validateCredentials(string $email, string $password): array
    {
        $this->logger->info("Login attempt for email: $email");
        $user = $this->userRepository->findByEmail($email);

        if ($user && $user->isActive() && password_verify($password, $user->getPasswordHash())) {
            $this->logger->info("Login successful for email: $email");
            return $this->issueTokensForUser($user);
        }

        $this->logger->warning("Failed login attempt for email: $email");
        throw new InvalidCredentialsException('Invalid credentials provided.');
    }

    public function decodeToken(string $token): ?object
    {
        return $this->tokenService->validateToken($token, 'access');
    }

    public function refreshTokens(string $refreshToken): array
    {
        $payload = $this->tokenService->validateToken($refreshToken, 'refresh');
        if ($payload === null) {
            throw new InvalidRefreshTokenException('Refresh token is not valid.');
        }

        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        if ($userId === null) {
            throw new InvalidRefreshTokenException('Refresh token missing user identifier.');
        }

        $user = $this->userRepository->findById($userId);
        if ($user === null || !$user->isActive()) {
            throw new InvalidRefreshTokenException('User not available for refresh.');
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

        $accessToken = $this->tokenService->generateAccessToken($payload, $this->sessionTimeoutSeconds);
        $refreshToken = $this->tokenService->generateRefreshToken(['uid' => $user->getId()], $this->refreshTokenTtlSeconds);

        $decodedAccess = $this->tokenService->validateToken($accessToken, 'access');
        $accessExp = $decodedAccess->exp ?? (time() + $this->sessionTimeoutSeconds);

        $decodedRefresh = $this->tokenService->validateToken($refreshToken, 'refresh');
        $refreshExp = $decodedRefresh->exp ?? (time() + $this->refreshTokenTtlSeconds);

        return [
            'payload' => $payload + ['exp' => $accessExp],
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'access_expires_at' => (int)$accessExp,
            'refresh_expires_at' => (int)$refreshExp,
        ];
    }
}
