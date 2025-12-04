<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\User\CreateUserUseCase;
use App\Application\User\DeleteUserUseCase;
use App\Application\User\Exception\UserNotFoundException;
use App\Application\User\Exception\UserValidationException;
use App\Application\User\ListUsersUseCase;
use App\Application\User\UpdateUserUseCase;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class UserController extends BaseController
{
    public function __construct(
        private readonly ListUsersUseCase $listUsers,
        private readonly CreateUserUseCase $createUser,
        private readonly UpdateUserUseCase $updateUser,
        private readonly DeleteUserUseCase $deleteUser,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        $users = $this->listUsers->execute();
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

        try {
            $created = $this->createUser->execute($input);
        } catch (UserValidationException $e) {
            http_response_code(422);
            $this->logWarning(422, 'Invalid user data', ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => $e->getMessage()]);
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

        try {
            $this->updateUser->execute($id, $input);
        } catch (UserValidationException $e) {
            http_response_code(422);
            $this->logWarning(422, $e->getMessage(), ['route' => RequestContext::getRoute(), 'user_id' => $id]);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        } catch (UserNotFoundException $e) {
            http_response_code(404);
            $this->logWarning(404, $e->getMessage(), ['route' => RequestContext::getRoute(), 'user_id' => $id]);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        http_response_code(200);
        echo json_encode(['status' => 'updated']);
    }

    public function delete(int $id): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        try {
            $this->deleteUser->execute($id);
        } catch (UserNotFoundException $e) {
            http_response_code(404);
            $this->logWarning(404, $e->getMessage(), ['route' => RequestContext::getRoute(), 'user_id' => $id]);
            echo json_encode(['error' => 'User not found']);
            return;
        }

        http_response_code(200);
        echo json_encode(['status' => 'deleted']);
    }

}
