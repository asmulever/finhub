<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\AuthService;
use App\Infrastructure\Logger;

class AuthController
{
    private Logger $logger;

    public function __construct(private readonly AuthService $authService)
    {
        $this->logger = new Logger();
    }

    public function validate(): void
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            $this->logger->warning("Invalid JSON payload for auth validation.");
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return;
        }

        $this->logger->info("Received validation request.");
        $token = $input['token'] ?? null;
        $email = $input['email'] ?? null;
        $password = $input['password'] ?? null;

        if ($token) {
            $this->logger->info("Validating token.");
            $payload = $this->authService->decodeToken($token);
            if ($payload !== null) {
                $this->logger->info("Token validation successful.");
                http_response_code(200);
                echo json_encode(['status' => 'valid_token', 'payload' => $payload]);
                return;
            }

            $this->logger->warning("Token validation failed.");
            http_response_code(401);
            echo json_encode(['status' => 'invalid_token']);
            return;
        }

        if ($email && $password) {
            $this->logger->info("Validating credentials for email: $email");
            $newToken = $this->authService->validateCredentials($email, $password);
            if ($newToken) {
                $this->logger->info("Credential validation successful for email: $email");
                http_response_code(200);
                echo json_encode(['status' => 'authenticated', 'token' => $newToken]);
            } else {
                $this->logger->warning("Credential validation failed for email: $email");
                http_response_code(401);
                echo json_encode(['status' => 'unauthenticated']);
            }
            return;
        }

        $this->logger->error("Bad request: no token or credentials provided.");
        http_response_code(400);
        echo json_encode(['error' => 'Bad Request']);
    }
}
