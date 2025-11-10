<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\AuthService;

class AuthController
{
    public function __construct(private readonly AuthService $authService)
    {
    }

    public function validate(): void
    {
        $input = json_decode(file_get_contents('php://input'), true);

        $token = $input['token'] ?? null;
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if ($token) {
            $isValid = $this->authService->validateToken($token);
            if ($isValid) {
                http_response_code(200);
                echo json_encode(['status' => 'valid_token']);
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'invalid_token']);
            }
            return;
        }

        if ($email && $password) {
            $newToken = $this->authService->validateCredentials($email, $password);
            if ($newToken) {
                http_response_code(200);
                echo json_encode(['status' => 'authenticated', 'token' => $newToken]);
            } else {
                http_response_code(401);
                echo json_encode(['status' => 'unauthenticated']);
            }
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Bad Request']);
    }
}
