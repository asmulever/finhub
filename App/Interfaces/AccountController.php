<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\Account\CreateAccountUseCase;
use App\Application\Account\DeleteAccountUseCase;
use App\Application\Account\Exception\AccountAccessException;
use App\Application\Account\Exception\AccountNotFoundException;
use App\Application\Account\Exception\AccountValidationException;
use App\Application\Account\ListAccountsUseCase;
use App\Application\Account\UpdateAccountUseCase;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class AccountController extends BaseController
{
    public function __construct(
        private readonly ListAccountsUseCase $listAccounts,
        private readonly CreateAccountUseCase $createAccount,
        private readonly UpdateAccountUseCase $updateAccount,
        private readonly DeleteAccountUseCase $deleteAccount,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        $payload = $this->authorize($this->jwtService);
        if ($payload === null) {
            return;
        }

        $accounts = $this->listAccounts->execute((int)$payload->uid);
        http_response_code(200);
        echo json_encode($accounts);
    }

    public function create(): void
    {
        $payload = $this->authorize($this->jwtService);
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        try {
            $created = $this->createAccount->execute((int)$payload->uid, $input);
        } catch (AccountValidationException $e) {
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
        $payload = $this->authorize($this->jwtService);
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        try {
            $updated = $this->updateAccount->execute((int)$payload->uid, $id, $input);
        } catch (AccountValidationException $e) {
            http_response_code(422);
            $this->logWarning(422, 'Invalid account data', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
            echo json_encode(['error' => 'Invalid account data']);
            return;
        } catch (AccountAccessException | AccountNotFoundException $e) {
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
        $payload = $this->authorize($this->jwtService);
        if ($payload === null) {
            return;
        }

        try {
            $this->deleteAccount->execute((int)$payload->uid, $id);
        } catch (AccountAccessException | AccountNotFoundException $e) {
            $this->logWarning(404, 'Account not found or unauthorized', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
            http_response_code(404);
            echo json_encode(['error' => 'Account not found']);
            return;
        }

        http_response_code(200);
        echo json_encode(['status' => 'deleted']);
    }

}
