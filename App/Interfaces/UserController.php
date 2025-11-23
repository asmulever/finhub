<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\UserService;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class UserController
{
    private Logger $logger;

    public function __construct(
        private readonly UserService $userService,
        private readonly JwtService $jwtService
    ) {
        $this->logger = new Logger();
    }

    public function list(): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $users = $this->userService->getAllUsers();
        http_response_code(200);
        echo json_encode($users);
    }

    public function create(): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $created = $this->userService->createUser($input);
        if ($created === null) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid user data']);
            return;
        }

        http_response_code(201);
        echo json_encode($created);
    }

    public function update(int $id): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        if ($this->userService->updateUser($id, $input)) {
            http_response_code(200);
            echo json_encode(['status' => 'updated']);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unable to update user']);
    }

    public function delete(int $id): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        if ($this->userService->deleteUser($id)) {
            http_response_code(204);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unable to delete user']);
    }

    private function authorizeAdmin(): ?object
    {
        $this->logger->info("Authorizing admin request.");
        $token = $this->getAccessToken();

        if ($token === null) {
            $this->logger->warning("Unauthorized access attempt: missing token.");
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logger->warning("Unauthorized access attempt: invalid token.");
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        if (($payload->role ?? '') !== 'admin') {
            $this->logger->warning("Forbidden operation for user {$payload->uid}, requires admin.");
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }

        return $payload;
    }

    private function getJsonInput(): ?array
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            $this->logger->warning("Invalid JSON payload received.");
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return null;
        }

        return $input;
    }

    private function getAccessToken(): ?string
    {
        $candidates = [
            $_SERVER['HTTP_AUTHORIZATION'] ?? null,
            $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null,
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && $value !== '' && preg_match('/Bearer\\s(\\S+)/', $value, $matches)) {
                return $matches[1];
            }
        }

        $headerFetcher = function_exists('getallheaders') ? 'getallheaders' : (function_exists('apache_request_headers') ? 'apache_request_headers' : null);
        if ($headerFetcher !== null) {
            $headers = $headerFetcher();
            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0 && preg_match('/Bearer\\s(\\S+)/', (string)$value, $matches)) {
                        return $matches[1];
                    }
                }
            }
        }

        $cookieToken = $_COOKIE['access_token'] ?? null;
        if (is_string($cookieToken) && $cookieToken !== '') {
            return $cookieToken;
        }

        return null;
    }
}
