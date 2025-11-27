<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\AccountService;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class AccountController extends BaseController
{
    public function __construct(
        private readonly AccountService $accountService,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $accounts = $this->accountService->listAccounts((int)$payload->uid);
        http_response_code(200);
        echo json_encode($accounts);
    }

    public function create(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $created = $this->accountService->createAccount((int)$payload->uid, $input);
        if ($created === null) {
            http_response_code(422);
            $this->logWarning(422, 'Invalid account data', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
            echo json_encode(['error' => 'Invalid account data']);
            return;
        }

        http_response_code(201);
        echo json_encode($created);
    }

    public function update(int $id): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $updated = $this->accountService->updateAccount((int)$payload->uid, $id, $input);
        if ($updated === null) {
            http_response_code(422);
            $this->logWarning(422, 'Unable to update account', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
            echo json_encode(['error' => 'Unable to update account']);
            return;
        }

        http_response_code(200);
        echo json_encode($updated);
    }

    public function delete(int $id): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        if ($this->accountService->deleteAccount((int)$payload->uid, $id)) {
            http_response_code(200);
            echo json_encode(['status' => 'deleted']);
            return;
        }

        $this->logWarning(404, 'Account not found or unauthorized', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
        http_response_code(404);
        echo json_encode(['error' => 'Account not found']);
    }

    private function authorize(): ?object
    {
        $this->logger()->info('Authorizing request for accounts.', ['origin' => static::class]);
        $token = $this->getAccessTokenFromRequest();

        if ($token === null) {
            $this->logWarning(401, 'Missing token for accounts route', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logWarning(401, 'Invalid token for accounts route', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);
        return $payload;
    }

}
