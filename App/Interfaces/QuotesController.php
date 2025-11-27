<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\QuotesService;
use App\Infrastructure\RequestContext;

class QuotesController extends BaseController
{
    public function __construct(private readonly QuotesService $quotesService)
    {
    }

    public function categories(): void
    {
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'categories' => $this->quotesService->getSupportedCategories(),
        ]);
    }

    public function list(string $category): void
    {
        try {
            $data = $this->quotesService->getQuotes($category);
        } catch (\InvalidArgumentException $e) {
            $this->logWarning(400, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'Invalid category']);
            return;
        } catch (\Throwable $e) {
            $this->logWarning(500, $e->getMessage(), ['route' => RequestContext::getRoute()]);
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Unable to fetch quotes']);
            return;
        }

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'category' => $data['category'],
            'updated_at' => $data['updated_at'],
            'items' => $data['items'],
        ]);
    }
}
