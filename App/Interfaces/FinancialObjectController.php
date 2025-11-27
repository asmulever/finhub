<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\FinancialObjectService;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class FinancialObjectController extends BaseController
{
    public function __construct(
        private readonly FinancialObjectService $financialObjectService,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $this->logger()->info("Authorization successful. Fetching financial objects for user {$payload->uid}.", ['origin' => static::class]);
        $objects = $this->financialObjectService->getAllFinancialObjects();
        http_response_code(200);
        echo json_encode($objects);
        $this->logger()->info("Successfully returned " . count($objects) . " financial objects.", ['origin' => static::class]);
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

        $created = $this->financialObjectService->createFinancialObject($input);
        if ($created === null) {
            http_response_code(400);
            $this->logWarning(400, 'Invalid financial object data', ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => 'Invalid financial object data']);
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

        if ($this->financialObjectService->updateFinancialObject($id, $input)) {
            http_response_code(200);
            echo json_encode(['status' => 'updated']);
            return;
        }

        $this->logWarning(400, 'Unable to update financial object', ['route' => RequestContext::getRoute()]);
        http_response_code(400);
        echo json_encode(['error' => 'Unable to update financial object']);
    }

    public function delete(int $id): void
    {
        $payload = $this->authorize(requireAdmin: true);
        if ($payload === null) {
            return;
        }

        if ($this->financialObjectService->deleteFinancialObject($id)) {
            http_response_code(200);
            echo json_encode(['status' => 'deleted']);
            return;
        }

        $this->logWarning(400, 'Unable to delete financial object', ['route' => RequestContext::getRoute()]);
        http_response_code(400);
        echo json_encode(['error' => 'Unable to delete financial object']);
    }

    private function authorize(bool $requireAdmin = false): ?object
    {
        $this->logger()->info("Authorizing request for financial objects.", ['origin' => static::class]);
        $token = $this->getAccessTokenFromRequest();

        if ($token === null) {
            $this->logger()->warning("Unauthorized access attempt: missing token.", ['origin' => static::class]);
            $this->logWarning(401, 'Missing token', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logger()->warning("Unauthorized access attempt: invalid token.", ['origin' => static::class]);
            $this->logWarning(401, 'Invalid token', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);

        if ($requireAdmin && (($payload->role ?? '') !== 'admin')) {
            $this->logger()->warning("Forbidden operation for user {$payload->uid}, requires admin.", ['origin' => static::class]);
            $this->logWarning(403, 'Forbidden access', ['route' => RequestContext::getRoute(), 'user_id' => $payload->uid ?? null]);
            http_response_code(403);
            echo json_encode(['error' => 'Forbidden']);
            return null;
        }

        return $payload;
    }

}
