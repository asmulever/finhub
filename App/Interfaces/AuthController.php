<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\AuthService;
use App\Infrastructure\Config;
use App\Infrastructure\Logger;

class AuthController
{
    private Logger $logger;
    private bool $secureCookies;

    public function __construct(private readonly AuthService $authService)
    {
        $this->logger = new Logger();
        $this->secureCookies = $this->shouldUseSecureCookies();
    }

    public function login(): void
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            $this->logger->warning("Invalid JSON payload for auth login.");
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if ($email && $password) {
            $this->logger->info("Validating credentials for email: $email");
            $tokens = $this->authService->validateCredentials($email, $password);
            if ($tokens !== null) {
                $this->logger->info("Credential validation successful for email: $email");
                $this->setAuthCookies(
                    $tokens['access_token'],
                    $tokens['access_expires_at'],
                    $tokens['refresh_token'],
                    $tokens['refresh_expires_at']
                );
                http_response_code(200);
                echo json_encode([
                    'status' => 'authenticated',
                    'payload' => $tokens['payload'],
                    'access_expires_at' => $tokens['access_expires_at'],
                    'refresh_expires_at' => $tokens['refresh_expires_at'],
                ]);
                return;
            }

            $this->logger->warning("Credential validation failed for email: $email");
            http_response_code(401);
            echo json_encode(['status' => 'unauthenticated']);
            return;
        }

        $this->logger->error("Bad request: credentials not provided.");
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request']);
    }

    public function refresh(): void
    {
        $refreshToken = $_COOKIE['refresh_token'] ?? null;
        if ($refreshToken === null || $refreshToken === '') {
            http_response_code(401);
            echo json_encode(['status' => 'no_refresh_token']);
            return;
        }

        $tokens = $this->authService->refreshTokens($refreshToken);
        if ($tokens === null) {
            http_response_code(401);
            echo json_encode(['status' => 'invalid_refresh_token']);
            return;
        }

        $this->setAuthCookies(
            $tokens['access_token'],
            $tokens['access_expires_at'],
            $tokens['refresh_token'],
            $tokens['refresh_expires_at']
        );

        http_response_code(200);
        echo json_encode([
            'status' => 'refreshed',
            'payload' => $tokens['payload'],
            'access_expires_at' => $tokens['access_expires_at'],
            'refresh_expires_at' => $tokens['refresh_expires_at'],
        ]);
    }

    public function session(): void
    {
        $token = $this->getAccessTokenFromRequest();
        if ($token === null) {
            http_response_code(401);
            echo json_encode(['status' => 'unauthenticated']);
            return;
        }

        $payload = $this->authService->decodeToken($token);
        if ($payload === null) {
            http_response_code(401);
            echo json_encode(['status' => 'unauthenticated']);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'status' => 'valid_token',
            'payload' => $payload,
            'access_expires_at' => $payload->exp ?? null,
        ]);
    }

    public function logout(): void
    {
        $this->clearAuthCookies();
        http_response_code(204);
    }

    private function setAuthCookies(string $accessToken, int $accessExpiresAt, string $refreshToken, int $refreshExpiresAt): void
    {
        $cookieConfig = [
            'expires' => $accessExpiresAt,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('access_token', $accessToken, $cookieConfig);

        $refreshCookieConfig = [
            'expires' => $refreshExpiresAt,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('refresh_token', $refreshToken, $refreshCookieConfig);
    }

    private function clearAuthCookies(): void
    {
        $cookieConfig = [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $this->secureCookies,
            'httponly' => true,
            'samesite' => 'Lax',
        ];
        setcookie('access_token', '', $cookieConfig);
        setcookie('refresh_token', '', $cookieConfig);
    }

    private function getAccessTokenFromRequest(): ?string
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;
        if ($authHeader && preg_match('/Bearer\\s(\\S+)/', $authHeader, $matches)) {
            return $matches[1];
        }

        $redirectHeader = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($redirectHeader && preg_match('/Bearer\\s(\\S+)/', $redirectHeader, $matches)) {
            return $matches[1];
        }

        $cookieToken = $_COOKIE['access_token'] ?? null;
        if ($cookieToken && $cookieToken !== '') {
            return $cookieToken;
        }

        return null;
    }

    private function shouldUseSecureCookies(): bool
    {
        $envOverride = Config::get('COOKIE_SECURE', null);
        if ($envOverride !== null) {
            return filter_var($envOverride, FILTER_VALIDATE_BOOL);
        }

        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? '') === '443';
    }
}
