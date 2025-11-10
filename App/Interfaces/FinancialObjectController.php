<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\FinancialObjectService;
use App\Infrastructure\JwtService;

class FinancialObjectController
{
    public function __construct(
        private readonly FinancialObjectService $financialObjectService,
        private readonly JwtService $jwtService
    ) {
    }

    public function list(): void
    {
        $authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? null;

        if ($authHeader === null || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $token = $matches[1];
        if (!$this->jwtService->validateToken($token)) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized']);
            return;
        }

        $objects = $this->financialObjectService->getAllFinancialObjects();
        http_response_code(200);
        echo json_encode($objects);
    }
}
