<?php

declare(strict_types=1);

namespace App\Infrastructure;

require_once __DIR__ . '/../lib/FirebaseJWT/JWT.php';
require_once __DIR__ . '/../lib/FirebaseJWT/Key.php';
require_once __DIR__ . '/../lib/FirebaseJWT/ExpiredException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/SignatureInvalidException.php';
require_once __DIR__ . '/../lib/FirebaseJWT/BeforeValidException.php';

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    public function __construct(private readonly string $secretKey)
    {
    }

    public function generateToken(array $payload): string
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + 3600;  // jwt valid for 1 hour
        $payload = array_merge($payload, [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
        ]);

        return JWT::encode($payload, $this->secretKey, 'HS256');
    }

    public function validateToken(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key($this->secretKey, 'HS256'));
        } catch (\Exception $e) {
            return null;
        }
    }
}
