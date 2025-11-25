<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\PortfolioService;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class PortfolioController extends BaseController
{
    private Logger $logger;

    public function __construct(
        private readonly PortfolioService $portfolioService,
        private readonly JwtService $jwtService
    ) {
        $this->logger = new Logger();
    }

    public function show(): void
    {
        $payload = $this->authorize();
        if ($payload === null) {
            return;
        }

        $data = $this->portfolioService->getPortfolioWithTickers((int)$payload->uid);
        http_response_code(200);
        echo json_encode($data);
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
            $this->logger->warning('Unable to add ticker: ' . $e->getMessage());
            http_response_code(400);
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
            $this->logger->warning('Unable to update ticker: ' . $e->getMessage());
            http_response_code(400);
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
            $this->logger->warning('Unable to delete ticker: ' . $e->getMessage());
            http_response_code(400);
            echo json_encode(['error' => $e->getMessage()]);
        }
    }

    private function getJsonInput(): ?array
    {
        $rawInput = file_get_contents('php://input');
        $input = json_decode($rawInput, true);

        if (!is_array($input)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid JSON']);
            return null;
        }

        return $input;
    }

    private function authorize(): ?object
    {
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

        return $payload;
    }
}
