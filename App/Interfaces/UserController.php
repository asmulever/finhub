<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\UserService;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class UserController extends BaseController
{
    public function __construct(
        private readonly UserService $userService,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        $users = $this->userService->getAllUsers();
        http_response_code(200);
        echo json_encode($users);
    }

    public function create(): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $created = $this->userService->createUser($input);
        if ($created === null) {
            http_response_code(400);
            $this->logWarning(400, 'Invalid user data', ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => 'Invalid user data']);
            return;
        }

        http_response_code(201);
        echo json_encode($created);
    }

    public function update(int $id): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
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

        $this->logWarning(400, 'Unable to update user', ['route' => RequestContext::getRoute(), 'user_id' => $id]);
        http_response_code(400);
        echo json_encode(['error' => 'Unable to update user']);
    }

    public function delete(int $id): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        if ($this->userService->deleteUser($id)) {
            http_response_code(200);
            echo json_encode(['status' => 'deleted']);
            return;
        }

        $this->logWarning(400, 'Unable to delete user', ['route' => RequestContext::getRoute(), 'user_id' => $id]);
        http_response_code(400);
        echo json_encode(['error' => 'Unable to delete user']);
    }

}
