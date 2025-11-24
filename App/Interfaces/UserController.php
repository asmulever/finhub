<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\UserService;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class UserController extends BaseController
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
            http_response_code(200);
            echo json_encode(['status' => 'deleted']);
            return;
        }

        http_response_code(400);
        echo json_encode(['error' => 'Unable to delete user']);
    }

    private function authorizeAdmin(): ?object
    {
        $this->logger->info("Authorizing admin request.");
        $token = $this->getAccessTokenFromRequest();

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

}
