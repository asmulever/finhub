<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Application\LogService;
use App\Interfaces\AccountController;
use App\Interfaces\AuthController;
use App\Interfaces\FinancialObjectController;
use App\Interfaces\LogController;
use App\Interfaces\PortfolioController;
use App\Interfaces\UserController;
use App\Interfaces\EtlController;
use App\Infrastructure\Container;

class Router
{
    private AuthController $authController;
    private FinancialObjectController $financialObjectController;
    private UserController $userController;
    private AccountController $accountController;
    private PortfolioController $portfolioController;
    private LogController $logController;
    private LogService $logService;
    private EtlController $etlController;

    public function __construct(private readonly Container $container)
    {
        // Ensure LogService is initialized before repositories request the singleton.
        $this->logService = $container->get(LogService::class);

        $this->authController = $container->get(AuthController::class);
        $this->financialObjectController = $container->get(FinancialObjectController::class);
        $this->userController = $container->get(UserController::class);
        $this->accountController = $container->get(AccountController::class);
        $this->portfolioController = $container->get(PortfolioController::class);
        $this->logController = $container->get(LogController::class);
        $this->etlController = $container->get(EtlController::class);
    }

    public function dispatch(string $requestUri, string $requestMethod): void
    {
        header('Content-Type: application/json; charset=UTF-8');

        RequestContext::bootstrap($_SERVER, $requestUri, $requestMethod);

        try {
            $uri = $this->normalizeUri($requestUri);
            RequestContext::setRoute($uri);
            $healthStatus = SchemaManager::ensureSchema();
            $this->handleRoute($uri, strtoupper($requestMethod), $healthStatus);
        } catch (\Throwable $e) {
            $this->respondWithException($e);
        }
    }

    private function handleRoute(string $uri, string $method, array $healthStatus): void
    {
        if (($uri === '/auth/login' || $uri === '/auth/validate') && $method === 'POST') {
            $this->authController->login();
            return;
        }

        if ($uri === '/auth/refresh' && $method === 'POST') {
            $this->authController->refresh();
            return;
        }

        if ($uri === '/auth/session' && $method === 'GET') {
            $this->authController->session();
            return;
        }

        if ($uri === '/auth/logout' && $method === 'POST') {
            $this->authController->logout();
            return;
        }

        if ($uri === '/health' && $method === 'GET') {
            http_response_code(200);
            echo json_encode([
                'status' => 'ok',
                'environment' => getenv('APP_ENV') ?: 'production',
                'database' => $healthStatus,
            ]);
            return;
        }

        if ($uri === '/financial-objects' && $method === 'GET') {
            $this->financialObjectController->list();
            return;
        }

        if ($uri === '/financial-objects' && $method === 'POST') {
            $this->financialObjectController->create();
            return;
        }

        if (preg_match('#^/financial-objects/(\d+)$#', $uri, $matches)) {
            $id = (int)$matches[1];
            if ($method === 'PUT') {
                $this->financialObjectController->update($id);
                return;
            }
            if ($method === 'DELETE') {
                $this->financialObjectController->delete($id);
                return;
            }
            $this->respondMethodNotAllowed();
            return;
        }

        if ($uri === '/accounts' && $method === 'GET') {
            $this->accountController->list();
            return;
        }

        if ($uri === '/accounts' && $method === 'POST') {
            $this->accountController->create();
            return;
        }

        if (preg_match('#^/accounts/(\d+)/(update|edit)$#', $uri, $matches) && $method === 'POST') {
            $this->accountController->update((int)$matches[1]);
            return;
        }

        if (preg_match('#^/accounts/(\d+)/(delete|remove)$#', $uri, $matches) && $method === 'POST') {
            $this->accountController->delete((int)$matches[1]);
            return;
        }

        if (preg_match('#^/accounts/(\d+)$#', $uri, $matches)) {
            $id = (int)$matches[1];
            if ($method === 'PUT') {
                $this->accountController->update($id);
                return;
            }
            if ($method === 'DELETE') {
                $this->accountController->delete($id);
                return;
            }
            $this->respondMethodNotAllowed();
            return;
        }

        if ($uri === '/portfolio' && $method === 'GET') {
            $this->portfolioController->show();
            return;
        }

        if ($uri === '/portfolio/tickers' && $method === 'POST') {
            $this->portfolioController->addTicker();
            return;
        }

        if (preg_match('#^/portfolio/tickers/(\d+)$#', $uri, $matches)) {
            $tickerId = (int)$matches[1];
            if ($method === 'PUT') {
                $this->portfolioController->updateTicker($tickerId);
                return;
            }
            if ($method === 'DELETE') {
                $this->portfolioController->deleteTicker($tickerId);
                return;
            }
            $this->respondMethodNotAllowed();
            return;
        }

        if ($uri === '/users' && $method === 'GET') {
            $this->userController->list();
            return;
        }

        if ($uri === '/users' && $method === 'POST') {
            $this->userController->create();
            return;
        }

        if (preg_match('#^/users/(\d+)/(delete|remove)$#', $uri, $matches) && $method === 'POST') {
            $this->userController->delete((int)$matches[1]);
            return;
        }

        if (preg_match('#^/users/(\d+)/(update|edit)$#', $uri, $matches) && $method === 'POST') {
            $this->userController->update((int)$matches[1]);
            return;
        }

        if (preg_match('#^/users/(\d+)$#', $uri, $matches)) {
            $id = (int)$matches[1];
            if ($method === 'PUT') {
                $this->userController->update($id);
                return;
            }
            if ($method === 'DELETE') {
                $this->userController->delete($id);
                return;
            }
            $this->respondMethodNotAllowed();
            return;
        }

        if ($uri === '/logs/frontend' && $method === 'POST') {
            $this->logController->ingestFrontend();
            return;
        }

        if ($uri === '/logs/filters' && $method === 'GET') {
            $this->logController->filters();
            return;
        }

        if ($uri === '/logs' && $method === 'GET') {
            $this->logController->list();
            return;
        }

        if (preg_match('#^/logs/(\d+)$#', $uri, $matches) && $method === 'GET') {
            $this->logController->show((int)$matches[1]);
            return;
        }

        if ($uri === '/etl/ingest' && $method === 'GET') {
            $this->etlController->ingest();
            return;
        }

        if ($uri === '/etl/normalize-prices' && $method === 'GET') {
            $this->etlController->normalizePrices();
            return;
        }

        if ($uri === '/etl/calc-indicators' && $method === 'GET') {
            $this->etlController->calcIndicators();
            return;
        }

        if ($uri === '/etl/calc-signals' && $method === 'GET') {
            $this->etlController->calcSignals();
            return;
        }

        http_response_code(404);
        $this->logService->warning('Route not found', [
            'route' => $uri,
            'http_status' => 404,
            'origin' => 'router',
        ]);
        echo json_encode([
            'error' => 'Not Found',
            'correlation_id' => RequestContext::getCorrelationId(),
        ]);
    }

    private function normalizeUri(string $requestUri): string
    {
        $uri = strtok($requestUri, '?') ?: '/';

        if (str_starts_with($uri, '/api')) {
            $uri = substr($uri, strlen('/api')) ?: '/';
        }

        if (str_starts_with($uri, '/index.php')) {
            $uri = substr($uri, strlen('/index.php')) ?: '/';
        }

        return $uri === '' ? '/' : $uri;
    }

    private function respondWithException(\Throwable $e): void
    {
        http_response_code(500);
        $this->logService->logException($e, 500, ['origin' => 'router']);
        echo json_encode([
            'error' => 'internal_error',
            'correlation_id' => RequestContext::getCorrelationId(),
        ]);
    }

    private function respondMethodNotAllowed(): void
    {
        http_response_code(405);
        $this->logService->warning('Method not allowed', [
            'http_status' => 405,
            'origin' => 'router',
        ]);
        echo json_encode([
            'error' => 'Method Not Allowed',
            'correlation_id' => RequestContext::getCorrelationId(),
        ]);
    }
}
