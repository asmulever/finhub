<?php

declare(strict_types=1);

namespace App\Infrastructure;

require_once __DIR__ . '/../lib/FirebaseJWT/JWTExceptionWithPayloadInterface.php';
require_once __DIR__ . '/../lib/FirebaseJWT/JWT.php';
require_once __DIR__ . '/../lib/FirebaseJWT/Key.php';
require_once __DIR__ . '/../lib/FirebaseJWT/ExpiredException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/SignatureInvalidException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/BeforeValidException.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private Logger $logger;

    public function __construct(private readonly string $secretKey)
    {
        $this->logger = new Logger();
    }

    public function generateToken(array $payload): string
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + 3600;  // jwt valid for 1 hour
        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
        ]);

        $token = JWT::encode($payload, $this->secretKey, 'HS256');
        $this->logger->info('JWT generated for user ID: ' . ($payload['uid'] ?? 'unknown'));
        return $token;
    }

    public function validateToken(string $token): ?object
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, 'HS256'));
            $this->logger->info('JWT validated successfully for user ID: ' . ($decoded->uid ?? 'unknown'));
            return $decoded;
        } catch (\Exception $e) {
            $this->logger->error('JWT validation failed: ' . $e->getMessage());
            return null;
        }
    }
}
