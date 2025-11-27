<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\PortfolioService;
use App\Infrastructure\JwtService;
use App\Infrastructure\RequestContext;

class PortfolioController extends BaseController
{
    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly JwtService $jwtService
    ) {
    }

    public function show(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $brokerId = isset($_GET['broker_id']) ? (int)$_GET['broker_id'] : 0;
        if ($brokerId <= 0) {
            $this->logWarning(400, 'Missing broker_id', ['route' => RequestContext::getRoute()]);
            http_response_code(400);
            echo json_encode(['error' => 'broker_id is required']);
            return;
        }

        try {
            $tickers = $this->portfolioService->getTickersForBroker((int)$payload->uid, $brokerId);
        } catch (\Throwable $e) {
            http_response_code(400);
            $this->logWarning(400, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => $e->getMessage()]);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'broker_id' => $brokerId,
            'tickers' => $tickers,
        ]);
    }

    public function addTicker(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        try {
            $created = $this->portfolioService->addTicker((int)$payload->uid, $input);
            http_response_code(201);
            echo json_encode($created);
        } catch (\Throwable $e) {
            $this->logger()->warning('Unable to add ticker: ' . $e->getMessage(), ['origin' => static::class]);
            http_response_code(400);
            $this->logWarning(400, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function updateTicker(int $tickerId): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        try {
            $this->portfolioService->updateTicker((int)$payload->uid, $tickerId, $input);
            http_response_code(200);
            echo json_encode(['status' => 'updated']);
        } catch (\Throwable $e) {
            $this->logger()->warning('Unable to update ticker: ' . $e->getMessage(), ['origin' => static::class]);
            http_response_code(400);
            $this->logWarning(400, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    public function deleteTicker(int $tickerId): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        try {
            $this->portfolioService->deleteTicker((int)$payload->uid, $tickerId);
            http_response_code(204);
        } catch (\Throwable $e) {
            $this->logger()->warning('Unable to delete ticker: ' . $e->getMessage(), ['origin' => static::class]);
            http_response_code(400);
            $this->logWarning(400, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function authorize(): ?object
    {
        $token = $this->getAccessTokenFromRequest();
        if ($token === null) {
            $this->logWarning(401, 'Missing token for portfolio routes', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $payload = $this->jwtService->validateToken($token, 'access');
        if ($payload === null) {
            $this->logWarning(401, 'Invalid token for portfolio routes', ['route' => RequestContext::getRoute()]);
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return null;
        }

        $this->recordAuthenticatedUser($payload);
        return $payload;
    }
}
