<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\FinancialObjectService;
use App\Infrastructure\JwtService;
use App\Infrastructure\Logger;

class FinancialObjectController
{
    private Logger $logger;

    public function __construct(
        private readonly FinancialObjectService $financialObjectService,
        private readonly JwtService $jwtService
    ) {
        $this->logger = new Logger();
    }

    public function list(): void
    {
        $this->logger->info("Attempting to list financial objects.");
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($authHeader === null || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            $this->logger->warning("Unauthorized access attempt: missing or malformed Authorization header.");
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $token = $matches[1];
        if (!$this->jwtService->validateToken($token)) {
            $this->logger->warning("Unauthorized access attempt: invalid token.");
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $this->logger->info("Authorization successful. Fetching financial objects.");
        $objects = $this->financialObjectService->getAllFinancialObjects();
        http_response_code(200);
        echo json_encode($objects);
        $this->logger->info("Successfully returned " . count($objects) . " financial objects.");
    }
}
