<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\AccountService;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class AccountController extends BaseController
{
    private Logger $logger;

    public function __construct(
        private readonly AccountService $accountService,
        private readonly JwtService $jwtService
    ) {
        $this->logger = new Logger();
    }

    public function list(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $userId = isset($payload->uid) ? (int)$payload->uid : null;
        $isAdmin = strtolower($payload->role ?? '') === 'admin';

        $accounts = $this->accountService->listAccounts($isAdmin, $userId);
        http_response_code(200);
        echo json_encode($accounts);
    }

    public function create(): void
    {
        $payload = $this->authorize(requireAdmin: true);
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $created = $this->accountService->createAccount($input);
        if ($created === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Invalid account data']);
            return;
        }

        http_response_code(201);
        echo json_encode($created);
    }

    public function update(int $id): void
    {
        $payload = $this->authorize(requireAdmin: true);
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $updated = $this->accountService->updateAccount($id, $input);
        if ($updated === null) {
            http_response_code(422);
            echo json_encode(['error' => 'Unable to update account']);
            return;
        }

        http_response_code(200);
        echo json_encode($updated);
    }

    public function delete(int $id): void
    {
        $payload = $this->authorize(requireAdmin: true);
        if ($payload === null) {
            return;
        }

        if ($this->accountService->deleteAccount($id)) {
            http_response_code(200);
            echo json_encode(['status' => 'deleted']);
            return;
        }

        http_response_code(404);
        echo json_encode(['error' => 'Account not found']);
    }

    private function authorize(bool $requireAdmin = false): ?object
    {
        $this->logger->info('Authorizing request for accounts.');
        $token = $this->getAccessTokenFromRequest();

        if ($token === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        if ($requireAdmin && strtolower($payload->role ?? '') !== 'admin') {
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
            $this->logger->warning('Invalid JSON payload for accounts controller.');
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return null;
        }

        return $input;
    }

}
