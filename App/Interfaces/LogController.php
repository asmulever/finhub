<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Application\LogService;
use App\Infrastructure\RequestContext;

class LogController extends BaseController
{
    public function __construct(private readonly LogService $logService)
    {
    }

    public function list(): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $filters = [
            'date_from' => $_GET['date_from'] ?? null,
            'date_to' => $_GET['date_to'] ?? null,
            'level' => $_GET['level'] ?? null,
            'http_status' => (isset($_GET['http_status']) && $_GET['http_status'] !== '') ? (int)$_GET['http_status'] : null,
            'route' => $_GET['route'] ?? null,
            'user_id' => (isset($_GET['user_id']) && $_GET['user_id'] !== '') ? (int)$_GET['user_id'] : null,
            'correlation_id' => $_GET['correlation_id'] ?? null,
        ];
        $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
        $pageSize = isset($_GET['page_size']) ? (int)$_GET['page_size'] : 25;

        $result = $this->logService->getLogs($filters, $page, $pageSize);
        http_response_code(200);
        echo json_encode($result);
    }

    public function show(int $id): void
    {
        if ($this->authorizeAdmin() === null) {
            return;
        }

        $log = $this->logService->getLogById($id);
        if ($log === null) {
            $this->logWarning(404, 'Log entry not found', ['route' => RequestContext::getRoute()]);
            http_response_code(404);
            echo json_encode(['error' => 'Log not found']);
            return;
        }

        http_response_code(200);
        echo json_encode($log);
    }
}
