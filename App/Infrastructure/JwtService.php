<?php

declare(strict_types=1);

namespace App\Infrastructure;

require_once __DIR__ . '/../lib/FirebaseJWT/JWTExceptionWithPayloadInterface.php';
require_once __DIR__ . '/../lib/FirebaseJWT/JWT.php';
require_once __DIR__ . '/../lib/FirebaseJWT/Key.php';
require_once __DIR__ . '/../lib/FirebaseJWT/ExpiredException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/SignatureInvalidException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/BeforeValidException.php';

use App\Application\LogService;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private LogService $logger;

    public function __construct(private readonly string $secretKey)
    {
        $this->logger = LogService::getInstance();
    }

    public function generateAccessToken(array $payload, int $ttlSeconds = 300): string
    {
        return $this->generateToken($payload, $ttlSeconds, 'access');
    }

    public function generateRefreshToken(array $payload, int $ttlSeconds = 604800): string
    {
        return $this->generateToken($payload, $ttlSeconds, 'refresh');
    }

    public function validateToken(string $token, string $expectedType = 'access'): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));

            $tokenType = $decoded->type ?? null;
            if ($expectedType !== '' && $tokenType !== null && $tokenType !== $expectedType) {
                $this->logger->warning('JWT validation failed: unexpected token type.');
                return null;
            }
            if ($expectedType !== '' && $tokenType === null && $expectedType !== 'access') {
                $this->logger->warning('JWT validation failed: token missing type.');
                return null;
            }

            $this->logger->info('JWT validated successfully for user ID: ' . ($decoded->uid ?? 'unknown'));
            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('JWT validation failed: ' . $e->getMessage());
            return null;
        }
    }

    private function generateToken(array $payload, int $ttlSeconds, string $type): string
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $ttlSeconds;
        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'type' => $type,
        ]);

        $token = JWT::encode($payload, $this->secretKey, 'HS256');
        $this->logger->info('JWT generated for user ID: ' . ($payload['uid'] ?? 'unknown'));
        return $token;
    }
}
