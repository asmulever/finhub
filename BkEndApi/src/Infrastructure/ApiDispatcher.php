<?php
declare(strict_types=1);

namespace FinHub\Infrastructure;

use FinHub\Application\Auth\AuthService;
use FinHub\Application\Auth\ActivationService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Application\Portfolio\PortfolioSummaryService;
use FinHub\Application\Portfolio\PortfolioHeatmapService;
use FinHub\Application\Analytics\PredictionService;
use FinHub\Application\LLM\OpenRouterClient;
use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\DataLake\InstrumentCatalogService;
use FinHub\Application\Ingestion\DataReadinessService;
use FinHub\Application\MarketData\RavaViewsService;
use FinHub\Application\Signals\SignalService;
use FinHub\Application\Backtest\BacktestRequest;
use FinHub\Application\Backtest\BacktestService;
use FinHub\Application\Cache\CacheInterface;
use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;
use FinHub\Infrastructure\User\UserDeletionService;

final class ApiDispatcher
{
    private Config $config;
    private LoggerInterface $logger;
    private string $objectStore;
    private AuthService $authService;
    private ActivationService $activationService;
    private UserRepositoryInterface $userRepository;
    private JwtTokenProvider $jwt;
    private PasswordHasher $passwordHasher;
    private PortfolioService $portfolioService;
    private PortfolioSummaryService $portfolioSummaryService;
    private PortfolioHeatmapService $portfolioHeatmapService;
    private PredictionService $predictionService;
    private \FinHub\Application\Analytics\PredictionMarketService $predictionMarketService;
    private OpenRouterClient $openRouterClient;
    private DataLakeService $dataLakeService;
    private InstrumentCatalogService $instrumentCatalogService;
    private RavaViewsService $ravaViewsService;
    private SignalService $signalService;
    private DataReadinessService $dataReadinessService;
    private BacktestService $backtestService;
    private UserDeletionService $userDeletionService;
    private CacheInterface $cache;
    /** Rutas base deben terminar sin barra final. */
    private string $apiBase;
    private const RADAR_ANALYSIS_TTL = 86400; // 24h
    private const RADAR_MODELS_TTL = 600;     // 10m

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        AuthService $authService,
        ActivationService $activationService,
        UserRepositoryInterface $userRepository,
        JwtTokenProvider $jwt,
        PasswordHasher $passwordHasher,
        PortfolioService $portfolioService,
        PortfolioSummaryService $portfolioSummaryService,
        PortfolioHeatmapService $portfolioHeatmapService,
        PredictionService $predictionService,
        \FinHub\Application\Analytics\PredictionMarketService $predictionMarketService,
        OpenRouterClient $openRouterClient,
        DataLakeService $dataLakeService,
        InstrumentCatalogService $instrumentCatalogService,
        RavaViewsService $ravaViewsService,
        SignalService $signalService,
        DataReadinessService $dataReadinessService,
        UserDeletionService $userDeletionService,
        BacktestService $backtestService,
        CacheInterface $cache
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->objectStore = rtrim($config->get('OBJECT_STORE_PATH', '/var/www/html/storage/object_store'), '/');
        $this->apiBase = rtrim($config->get('API_BASE_PATH', '/api'), '/');
        $this->authService = $authService;
        $this->activationService = $activationService;
        $this->userRepository = $userRepository;
        $this->jwt = $jwt;
        $this->passwordHasher = $passwordHasher;
        $this->portfolioService = $portfolioService;
        $this->portfolioSummaryService = $portfolioSummaryService;
        $this->portfolioHeatmapService = $portfolioHeatmapService;
        $this->predictionService = $predictionService;
        $this->predictionMarketService = $predictionMarketService;
        $this->openRouterClient = $openRouterClient;
        $this->dataLakeService = $dataLakeService;
        $this->instrumentCatalogService = $instrumentCatalogService;
        $this->ravaViewsService = $ravaViewsService;
        $this->signalService = $signalService;
        $this->dataReadinessService = $dataReadinessService;
        $this->backtestService = $backtestService;
        $this->userDeletionService = $userDeletionService;
        $this->cache = $cache;
    }

    /**
     * Ejecuta el ciclo completo de la petición: CORS, routing y respuestas JSON.
     */
    public function dispatch(string $traceId): void
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri = parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';
        $this->sendCorsHeaders();
        if ($method === 'OPTIONS') {
            $this->sendJson(['result' => 'ok'], 204);
            return;
        }
        $path = $this->getRoutePath($uri);
        error_log(sprintf('api.route method=%s uri=%s path=%s base=%s', $method, $uri, $path, $this->apiBase));
        try {
            $this->route($method, $path, $traceId);
        } catch (\Throwable $throwable) {
            $this->logRequestError($method, $path, $traceId, $throwable);
            $this->sendError($throwable, $traceId);
        }
    }

    /**
     * Define la lógica de enrutamiento y genera las respuestas específicas.
     */
    private function route(string $method, string $path, string $traceId): void
    {
        if ($method === 'GET' && $path === '/health') {
            $this->sendJson(['status' => 'ok', 'trace_id' => $traceId]);
            return;
        }
        // Object store (R2Lite UI "Storage")
        if ($method === 'GET' && $path === '/objects') {
            $this->handleObjectList();
            return;
        }
        if (preg_match('#^/objects/(.+)$#', $path, $m) === 1) {
            $key = $m[1];
            if ($method === 'HEAD') { $this->handleObjectHead($key); return; }
            if ($method === 'GET')  { $this->handleObjectGet($key); return; }
            if ($method === 'PUT')  { $this->handleObjectPut($key); return; }
            if ($method === 'DELETE') { $this->handleObjectDelete($key); return; }
        }
        if ($method === 'GET' && $path === '/status') {
            $this->sendJson([
                'status' => 'ok',
                'env' => $this->config->get('APP_ENV', 'production'),
                'trace_id' => $traceId,
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/me') {
            $user = $this->requireUser();
            $this->sendJson($user->toResponse());
            return;
        }
        if ($method === 'POST' && $path === '/backtests/run') {
            $user = $this->requireUser();
            $this->handleBacktestRun($user);
            return;
        }
        if ($method === 'GET' && preg_match('#^/backtests/(\\d+)$#', $path, $m) === 1) {
            $this->requireUser();
            $this->handleBacktestGet((int) $m[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/backtests/(\\d+)/metrics$#', $path, $m) === 1) {
            $this->requireUser();
            $this->handleBacktestMetrics((int) $m[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/backtests/(\\d+)/equity$#', $path, $m) === 1) {
            $this->requireUser();
            $this->handleBacktestEquity((int) $m[1]);
            return;
        }
        if ($method === 'GET' && preg_match('#^/backtests/(\\d+)/trades$#', $path, $m) === 1) {
            $this->requireUser();
            $this->handleBacktestTrades((int) $m[1]);
            return;
        }
        if ($method === 'GET' && $path === '/signals/latest') {
            $user = $this->requireUser();
            $this->handleSignalsLatest($user);
            return;
        }
        if ($method === 'GET' && $path === '/datalake/prices/captures') {
            $this->handleCaptureList();
            return;
        }
        if ($method === 'GET' && $path === '/datalake/prices/symbols') {
            $user = $this->requireUser();
            $symbols = $this->portfolioService->listSymbols($user->getId());
            $this->sendJson(['symbols' => $symbols]);
            return;
        }
        if ($method === 'GET' && $path === '/datalake/prices/series') {
            $this->handlePriceSeries();
            return;
        }
        if ($method === 'GET' && $path === '/datalake/prices/latest') {
            $this->handleLatestPrice();
            return;
        }
        if ($method === 'GET' && $path === '/datalake/catalog') {
            $this->requireUser();
            $query = trim((string) ($_GET['q'] ?? ''));
            $tipo = trim((string) ($_GET['tipo'] ?? ''));
            $panel = trim((string) ($_GET['panel'] ?? ''));
            $mercado = trim((string) ($_GET['mercado'] ?? ''));
            $currency = strtoupper(trim((string) ($_GET['currency'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? 200);
            $offset = (int) ($_GET['offset'] ?? 0);
            $limit = max(1, min($limit, 500));
            $offset = max(0, $offset);
            $items = $this->instrumentCatalogService->search(
                $query !== '' ? $query : null,
                $tipo !== '' ? $tipo : null,
                $panel !== '' ? $panel : null,
                $mercado !== '' ? $mercado : null,
                $currency !== '' ? $currency : null,
                $limit,
                $offset
            );
            $this->sendJson([
                'data' => $items,
                'count' => count($items),
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/rava/catalog') {
            $this->handleRavaCatalog();
            return;
        }
        if ($method === 'GET' && $path === '/rava/dolares') {
            $this->handleRavaDolares();
            return;
        }
        if ($method === 'POST' && $path === '/analytics/predictions/run') {
            $this->handlePredictionRunGlobal($traceId);
            return;
        }
        if ($method === 'POST' && $path === '/analytics/predictions/run/me') {
            $user = $this->requireUser();
            $this->handlePredictionRunMe($user, $traceId);
            return;
        }
        if ($method === 'GET' && $path === '/analytics/predictions/latest') {
            $user = $this->requireUser();
            $this->handlePredictionLatest($user);
            return;
        }
        if ($method === 'GET' && $path === '/prediction/trending') {
            $this->handlePredictionTrending();
            return;
        }
        if ($method === 'GET' && $path === '/llm/models') {
            $this->handleLlmModels();
            return;
        }
        if ($method === 'GET' && $path === '/llm/models/openrouter') {
            $this->handleOpenRouterModels();
            return;
        }
        if ($method === 'POST' && $path === '/llm/radar/analyze') {
            $user = $this->requireUser();
            $this->handleLlmRadarAnalyze($user);
            return;
        }
        if ($method === 'GET' && $path === '/portfolio/instruments') {
            $user = $this->requireUser();
            $items = $this->portfolioService->listInstruments($user->getId());
            $this->sendJson(['data' => $items]);
            return;
        }
        if ($method === 'GET' && $path === '/portfolio/summary') {
            $user = $this->requireUser();
            $data = $this->portfolioSummaryService->summaryForUser($user->getId());
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($method === 'GET' && $path === '/portfolio/heatmap') {
            $user = $this->requireUser();
            $symbols = $this->portfolioService->listSymbols($user->getId());
            $readiness = $this->dataReadinessService->ensureSeriesReady($symbols, '3m', 60, 2);
            $this->logReadinessWarnings('portfolio.heatmap', $readiness, $user->getId());
            $data = $this->portfolioHeatmapService->build($user->getId());
            $data = $this->attachReadinessWarnings($data, $readiness);
            $this->sendJson($data);
            return;
        }
        if ($method === 'GET' && $path === '/portfolios') {
            $user = $this->requireUser();
            $items = $this->portfolioService->listPortfolios($user->getId());
            $this->sendJson(['data' => $items]);
            return;
        }
        if ($method === 'POST' && $path === '/portfolio/instruments') {
            $user = $this->requireUser();
            $this->handleAddInstrument($user);
            return;
        }
        if ($method === 'DELETE' && preg_match('#^/portfolio/instruments/(.+)$#', $path, $matches)) {
            $user = $this->requireUser();
            $symbol = urldecode((string) ($matches[1] ?? ''));
            $this->handleRemoveInstrument($user, $symbol);
            return;
        }
        if ($method === 'GET' && $path === '/users') {
            $this->requireAdmin();
            $users = $this->userRepository->listAll();
            $payload = array_map(static fn ($user) => $user->toResponse(), $users);
            $this->sendJson(['data' => $payload]);
            return;
        }
        if ($method === 'POST' && $path === '/users') {
            $this->requireAdmin();
            $this->handleCreateUser();
            return;
        }
        if (str_starts_with($path, '/users/') && preg_match('#^/users/(\\d+)$#', $path, $matches)) {
            $userId = (int) $matches[1];
            if ($method === 'PATCH') {
                $this->requireAdmin();
                $this->handleUpdateUser($userId);
                return;
            }
            if ($method === 'DELETE') {
                $this->requireAdmin();
                $this->handleDeleteUser($userId, $traceId);
                return;
            }
        }
        if ($method === 'POST' && $path === '/auth/login') {
            $this->handleLogin();
            return;
        }
        if ($method === 'POST' && $path === '/auth/register') {
            $this->handleRegister($traceId);
            return;
        }
        if ($method === 'GET' && $path === '/auth/activate') {
            $this->handleActivate($traceId);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    /**
     * Envía el payload JSON con los encabezados apropiados y código HTTP.
     */
    private function sendJson(array $payload, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Envía una respuesta HTML simple con el código HTTP indicado.
     */
    private function sendHtml(string $body, int $status = 200): void
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: text/html; charset=utf-8');
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        echo $body;
        exit;
    }

    /**
     * Calcula el path relativo al API base configurado.
     */
    private function getRoutePath(string $uri): string
    {
        $path = $uri;
        if ($this->apiBase !== '' && str_starts_with($uri, $this->apiBase)) {
            $path = substr($uri, strlen($this->apiBase));
        }
        // Normaliza: un solo slash inicial y sin slash final (excepto raíz).
        $normalized = '/' . trim($path, '/');
        return $normalized === '/' ? '/' : $normalized;
    }

    private function handleLogin(): void
    {
        $data = $this->parseJsonBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new \RuntimeException('Email y contraseña requeridos', 422);
        }

        $payload = $this->authService->authenticate($email, $password);
        $this->sendJson($payload);
    }

    private function handleRavaCatalog(): void
    {
        $result = $this->ravaViewsService->fetchCatalog();
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'count' => $result['count'] ?? 0,
            'counts' => $result['counts'] ?? [],
            'fetched_at' => $result['fetched_at'] ?? null,
        ]);
    }

    private function handleRavaDolares(): void
    {
        $result = $this->ravaViewsService->fetchDolares();
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'count' => $result['count'] ?? 0,
            'fetched_at' => $result['fetched_at'] ?? null,
        ]);
    }

    private function handleActivate(string $traceId): void
    {
        $token = isset($_GET['token']) ? trim((string) $_GET['token']) : '';
        if ($token === '') {
            $this->logger->error('activation.token.missing', ['trace_id' => $traceId]);
            $this->sendActivationPage('Enlace inválido', 'Falta el token de activación.', 400);
        }

        try {
            $payload = $this->jwt->decode($token);
        } catch (\Throwable $e) {
            $this->logger->error('activation.token.invalid', [
                'trace_id' => $traceId,
                'message' => $e->getMessage(),
            ]);
            $this->sendActivationPage('Enlace inválido', 'El enlace de activación es inválido o expiró.', 400);
        }

        if (($payload['type'] ?? '') !== 'activation') {
            $this->logger->error('activation.token.wrong_type', ['trace_id' => $traceId]);
            $this->sendActivationPage('Enlace incorrecto', 'Este enlace no corresponde a una activación.', 400);
        }

        $userId = (int) ($payload['sub'] ?? 0);
        $email = (string) ($payload['email'] ?? '');
        $user = $this->userRepository->findById($userId);
        if ($user === null || ($email !== '' && $user->getEmail() !== $email)) {
            $this->logger->error('activation.user.not_found', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'email' => $email,
            ]);
            $this->sendActivationPage('Cuenta no encontrada', 'No pudimos validar tu cuenta.', 404);
        }

        if ($user->isActive()) {
            $this->sendActivationPage('Cuenta ya activada', 'Tu cuenta ya está activa. Puedes iniciar sesión.', 200);
        }

        $updated = $this->userRepository->update($user->getId(), ['status' => 'active']);
        if ($updated === null) {
            $this->logger->error('activation.update.failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
            ]);
            $this->sendActivationPage('No se pudo activar', 'Intenta nuevamente más tarde.', 500);
        }

        $this->logger->info('activation.success', [
            'trace_id' => $traceId,
            'user_id' => $userId,
            'email' => $user->getEmail(),
        ]);
        $this->sendActivationPage('Cuenta activada', 'Tu cuenta fue activada. Ya puedes ingresar.', 200);
    }

    private function sendActivationPage(string $title, string $message, int $status = 200): void
    {
        $baseUrl = rtrim((string) $this->config->get('APP_BASE_URL', ''), '/');
        $apiBase = '/' . trim((string) $this->config->get('API_BASE_PATH', '/api'), '/');
        $redirectUrl = '/';
        if ($baseUrl !== '') {
            $redirectUrl = $baseUrl;
            if (str_ends_with($baseUrl, $apiBase)) {
                $redirectUrl = rtrim(substr($baseUrl, 0, -strlen($apiBase)), '/');
                if ($redirectUrl === '') {
                    $redirectUrl = '/';
                }
            }
        }
        $html = <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>{$title} | FinHub</title>
  <meta http-equiv="refresh" content="5;url={$redirectUrl}">
  <style>
    body { margin:0; font-family: 'Segoe UI', Arial, sans-serif; background: radial-gradient(circle at 20% 20%, rgba(56,189,248,0.08), transparent 35%), #0b1224; color: #e2e8f0; display:flex; align-items:center; justify-content:center; min-height:100vh; padding: 20px; }
    .card { max-width: 520px; width: 100%; background: linear-gradient(145deg, rgba(12, 21, 45, 0.95), rgba(7, 12, 26, 0.93)); border: 1px solid rgba(56,189,248,0.3); border-radius: 20px; padding: 28px; box-shadow: 0 18px 40px rgba(0,0,0,0.45); text-align: center; }
    h1 { margin: 0 0 12px; font-size: 24px; color: #cbd5f5; }
    p { margin: 0 0 16px; color: #cbd5e1; line-height: 1.6; }
    .button { display: inline-block; padding: 12px 18px; background: linear-gradient(120deg, #38bdf8, #2563eb); color: #0b1224; border-radius: 12px; text-decoration: none; font-weight: 700; letter-spacing: 0.02em; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.35); }
    .button:hover { transform: translateY(-1px); }
    .muted { color: #94a3b8; font-size: 0.95rem; }
  </style>
</head>
<body>
  <div class="card">
    <h1>{$title}</h1>
    <p>{$message}</p>
    <p><a class="button" href="{$redirectUrl}">Ir a FinHub</a></p>
    <p class="muted">Redirigiremos automáticamente en 5 segundos.</p>
    <p class="muted">Si no solicitaste esta cuenta, puedes ignorar este mensaje.</p>
  </div>
  <script>setTimeout(() => { window.location.href = '{$redirectUrl}'; }, 5000);</script>
</body>
</html>
HTML;
        $this->sendHtml($html, $status);
    }

    private function handleRegister(string $traceId): void
    {
        $data = $this->parseJsonBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        $result = $this->activationService->registerAndSendActivation($email, $password);
        $mailResult = $result['mail_result'];
        $mailStatus = $mailResult->getStatusCode();

        if ($mailResult->isSuccess()) {
            $this->logger->info('mail.activation.sent', [
                'trace_id' => $traceId,
                'email' => $email,
                'status_code' => $mailStatus,
                'activation_url' => $result['activation_url'],
            ]);
        } else {
            $this->logger->error('mail.activation.failed', [
                'trace_id' => $traceId,
                'email' => $email,
                'status_code' => $mailStatus,
                'body' => $mailResult->getBody(),
                'activation_url' => $result['activation_url'],
            ]);
            throw new \RuntimeException('No se pudo enviar el correo de activación', 502);
        }

        $this->sendJson($result['user']->toResponse(), 201);
    }

    private function handleCreateUser(): void
    {
        $data = $this->parseJsonBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $role = trim((string) ($data['role'] ?? ''));
        $status = trim((string) ($data['status'] ?? 'active'));

        if ($email === '' || $password === '' || $role === '') {
            throw new \RuntimeException('Email, contraseña y rol requeridos', 422);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email inválido', 422);
        }

        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null) {
            throw new \RuntimeException('Email ya registrado', 409);
        }

        $hash = $this->passwordHasher->hash($password);
        $user = $this->userRepository->create($email, $role, $status !== '' ? $status : 'active', $hash);
        $this->sendJson($user->toResponse(), 201);
    }

    private function handleUpdateUser(int $userId): void
    {
        $data = $this->parseJsonBody();
        $fields = [];
        if (array_key_exists('role', $data)) {
            $fields['role'] = trim((string) $data['role']);
        }
        if (array_key_exists('status', $data)) {
            $fields['status'] = trim((string) $data['status']);
        }
        if (array_key_exists('password', $data) && (string) $data['password'] !== '') {
            $fields['password_hash'] = $this->passwordHasher->hash((string) $data['password']);
        }
        if (empty($fields)) {
            throw new \RuntimeException('Sin cambios para actualizar', 422);
        }
        $user = $this->userRepository->update($userId, $fields);
        if ($user === null) {
            throw new \RuntimeException('Usuario no encontrado', 404);
        }
        $this->sendJson($user->toResponse());
    }

    private function handleDeleteUser(int $userId, string $traceId): void
    {
        try {
            $deleted = $this->userDeletionService->deleteCascade($userId);
            if (!$deleted) {
                throw new \RuntimeException('Usuario no encontrado', 404);
            }
            $this->logger->info('user.delete.cascade', ['trace_id' => $traceId, 'user_id' => $userId]);
            $this->sendJson(['deleted' => true, 'mode' => 'hard']);
        } catch (\Throwable $e) {
            $this->logger->error('user.delete.cascade_failed', [
                'trace_id' => $traceId,
                'user_id' => $userId,
                'message' => $e->getMessage(),
            ]);
            $code = $e->getCode() === 404 ? 404 : 500;
            throw new \RuntimeException($code === 404 ? 'Usuario no encontrado' : 'No se pudo eliminar en cascada', $code);
        }
    }

    /**
     * Lista grupos de capturas o devuelve las capturas de un bucket específico.
     */
    private function handleCaptureList(): void
    {
        $group = strtolower(trim((string) ($_GET['group'] ?? 'minute')));
        if (!in_array($group, ['minute', 'hour', 'date'], true)) {
            $group = 'minute';
        }
        $bucket = isset($_GET['bucket']) ? trim((string) $_GET['bucket']) : null;
        $symbol = isset($_GET['symbol']) ? strtoupper(trim((string) $_GET['symbol'])) : null;

        if ($bucket === null || $bucket === '') {
            $groups = $this->dataLakeService->captureGroups($group);
            $this->sendJson([
                'group' => $group,
                'groups' => $groups,
            ]);
            return;
        }

        $items = $this->dataLakeService->capturesByBucket($bucket, $group, $symbol ?: null);
        $this->sendJson([
            'group' => $group,
            'bucket' => $bucket,
            'count' => count($items),
            'items' => $items,
        ]);
    }

    /**
     * Devuelve la serie temporal para un símbolo en un período.
     */
    private function handlePriceSeries(): void
    {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        $period = trim((string) ($_GET['period'] ?? '1m'));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $series = $this->dataLakeService->series($symbol, $period);
        $this->sendJson($series);
    }

    /**
     * Devuelve señales calculadas y sus indicadores asociados.
     */
    private function handleSignalsLatest(\FinHub\Domain\User\User $user): void
    {
        $raw = $_GET['s'] ?? null;
        $symbols = [];
        if (is_string($raw)) {
            $symbols = array_filter(array_map('trim', explode(',', $raw)));
        } elseif (is_array($raw)) {
            foreach ($raw as $chunk) {
                $parts = preg_split('/,/', (string) $chunk, -1, PREG_SPLIT_NO_EMPTY);
                foreach ($parts as $p) {
                    $symbols[] = trim($p);
                }
            }
        }
        if (empty($symbols)) {
            $symbols = $this->portfolioService->listSymbols($user->getId());
        }
        if (empty($symbols)) {
            throw new \RuntimeException('No hay símbolos en tu portafolio para generar señales', 400);
        }
        $horizon = (int) ($_GET['horizon'] ?? 90);
        $forceRecompute = isset($_GET['force']) && in_array((string) $_GET['force'], ['1', 'true', 'yes'], true);
        $collectMissing = isset($_GET['collect']) && in_array((string) $_GET['collect'], ['1', 'true', 'yes'], true);
        $readiness = $this->dataReadinessService->ensureSeriesReady($symbols, '6m', 120, 2);
        $this->logReadinessWarnings('signals.latest', $readiness, $user->getId());
        $signals = $this->signalService->latest($symbols, $horizon, $forceRecompute, $collectMissing);
        $payload = $this->attachReadinessWarnings(['data' => $signals], $readiness);
        $this->sendJson($payload);
    }

    /**
     * Devuelve el último precio disponible para un símbolo desde Data Lake.
     */
    private function handleLatestPrice(): void
    {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        try {
            $quote = $this->dataLakeService->latestQuote($symbol);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.latest.not_found', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }

        $this->sendJson([
            'symbol' => $quote['symbol'] ?? $symbol,
            'close' => $quote['close'] ?? null,
            'open' => $quote['open'] ?? null,
            'high' => $quote['high'] ?? null,
            'low' => $quote['low'] ?? null,
            'previous_close' => $quote['previous_close'] ?? $quote['previousClose'] ?? null,
            'currency' => $quote['currency'] ?? null,
            'asOf' => $quote['asOf'] ?? $quote['as_of'] ?? null,
            'source' => $quote['source'] ?? $quote['provider'] ?? 'datalake',
        ]);
    }

    /**
     * Lanza predicciones para todos los usuarios (endpoint público).
     */
    private function handlePredictionRunGlobal(string $traceId): void
    {
        $result = $this->predictionService->runGlobal();
        $status = $result['status'] === 'running' ? 202 : ($result['status'] === 'failed' ? 500 : 200);
        $this->logger->info('analytics.prediction.run_global', [
            'trace_id' => $traceId,
            'status' => $result['status'] ?? '',
        ]);
        $this->sendJson($result, $status);
    }

    /**
     * Lanza predicciones solo para el usuario autenticado.
     */
    private function handlePredictionRunMe(\FinHub\Domain\User\User $user, string $traceId): void
    {
        $symbols = $this->portfolioService->listSymbols($user->getId());
        $readiness = $this->dataReadinessService->ensureSeriesReady($symbols, '6m', 120, 2);
        $this->logReadinessWarnings('analytics.prediction.run_user', $readiness, $user->getId());
        $result = $this->predictionService->runForUser($user->getId());
        $result = $this->attachReadinessWarnings($result, $readiness);
        $status = $result['status'] === 'running' ? 202 : ($result['status'] === 'failed' ? 500 : 200);
        $this->logger->info('analytics.prediction.run_user', [
            'trace_id' => $traceId,
            'user_id' => $user->getId(),
            'status' => $result['status'] ?? '',
        ]);
        $this->sendJson($result, $status);
    }

    /**
     * Obtiene las predicciones más recientes del usuario autenticado.
     */
    private function handlePredictionLatest(\FinHub\Domain\User\User $user): void
    {
        $result = $this->predictionService->latestForUser($user->getId());
        $this->sendJson($result);
    }

    /**
     * Devuelve trending de prediction markets (Yahoo Finance) con deltas.
     */
    private function handlePredictionTrending(): void
    {
        $result = $this->predictionMarketService->getTrending();
        $payload = [
            'as_of' => $result['as_of'] ?? null,
            'items' => $result['items'] ?? [],
            'cache' => $result['cache'] ?? null,
            'previous_snapshot' => $result['previous_snapshot'] ?? null,
        ];
        $this->sendJson($payload);
    }

    private function handleOpenRouterModels(): void
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/openrouter_models.json';
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (file_exists($cacheFile)) {
            $content = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($content) && ($content['date'] ?? '') === $today && isset($content['data'])) {
                $this->sendJson(['data' => $content['data'], 'cache' => true]);
                return;
            }
        }
        $raw = $this->openRouterClient->listModels();
        $items = $this->filterAndSortModels($raw['data'] ?? []);
        @file_put_contents($cacheFile, json_encode(['date' => $today, 'data' => $items]));
        $this->sendJson(['data' => $items, 'cache' => false]);
    }

    /**
     * @param array<int,array<string,mixed>> $models
     * @return array<int,array<string,mixed>>
     */
    private function filterAndSortModels(array $models): array
    {
        $filtered = array_values(array_filter($models, static function ($m) {
            $id = (string) ($m['id'] ?? '');
            return $id !== '' && str_ends_with($id, ':free');
        }));
        usort($filtered, static function ($a, $b) {
            $ra = (bool) ($a['supports_reasoning'] ?? false);
            $rb = (bool) ($b['supports_reasoning'] ?? false);
            if ($ra !== $rb) return $ra ? -1 : 1;
            $cla = (int) ($a['context_length'] ?? 0);
            $clb = (int) ($b['context_length'] ?? 0);
            if ($cla !== $clb) return $clb <=> $cla;
            $va = (bool) ($a['supports_vision'] ?? false);
            $vb = (bool) ($b['supports_vision'] ?? false);
            if ($va !== $vb) return $va ? -1 : 1;
            $ta = (bool) preg_match('/turbo|distill/i', (string) ($a['id'] ?? ''));
            $tb = (bool) preg_match('/turbo|distill/i', (string) ($b['id'] ?? ''));
            if ($ta !== $tb) return $ta ? 1 : -1; // no-turbo first
            return strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? ''));
        });
        return array_map(static function ($m) {
            return [
                'id' => (string) ($m['id'] ?? ''),
                'context_length' => $m['context_length'] ?? null,
                'supports_reasoning' => (bool) ($m['supports_reasoning'] ?? false),
                'supports_vision' => (bool) ($m['supports_vision'] ?? false),
            ];
        }, $filtered);
    }

    private function handleLlmModels(): void
    {
        $cacheKey = 'radar:models:list';
        $cached = $this->cache->get($cacheKey, null);
        if (is_array($cached)) {
            $this->sendJson($cached + ['cache' => true]);
        }
        $result = $this->openRouterClient->listModels();
        $filtered = $this->filterAndSortModels($result['data'] ?? []);
        $payload = ['data' => $filtered];
        $this->cache->set($cacheKey, $payload, self::RADAR_MODELS_TTL);
        $this->sendJson($payload);
    }

    private function handleLlmRadarAnalyze(\FinHub\Domain\User\User $user): void
    {
        $payload = $this->parseJsonBody();
        $model = (string) ($payload['model'] ?? 'kimi-k2-thinking');
        $risk = (string) ($payload['risk_profile'] ?? 'moderado');
        $note = trim((string) ($payload['note'] ?? ''));
        $baseCurrency = $this->portfolioService->getBaseCurrency($user->getId());
        $symbols = $this->portfolioService->listSymbols($user->getId());
        if (empty($symbols)) {
            throw new \RuntimeException('El portafolio no tiene tickers para analizar', 422);
        }

        $portfolioHash = substr(hash('sha256', implode(',', $symbols)), 0, 16);
        $noteHash = $note !== '' ? substr(hash('sha256', $note), 0, 16) : 'none';
        $riskKey = $risk !== '' ? $risk : 'moderado';
        $modelKey = $model !== '' ? $model : 'auto';
        $cacheKey = sprintf(
            'radar:analysis:user:%d:%s:%s:%s:%s',
            $user->getId(),
            $riskKey,
            $modelKey,
            $portfolioHash,
            $noteHash
        );
        $cached = $this->cache->get($cacheKey, null);
        if (is_array($cached) && isset($cached['raw_text'])) {
            $this->sendJson($cached + ['cache' => true]);
            return;
        }

        $systemPrompt = implode("\n", [
            'Sos trader y analista financiero senior. Analiza el portafolio del usuario autenticado.',
            'Para cada ticker decide: buy / hold / sell.',
            'Devuelve JSON con clave \"analysis\": array de {symbol, decision, confidence_pct, horizon_days, thesis, catalysts, risks, toast}.',
            'Usá español conciso, máximo 3 bullets por sección. Si falta data reciente, menciónalo en risks.',
        ]);

        $userPrompt = sprintf(
            'Perfil de riesgo: %s. Moneda base: %s. Analiza y decide para: %s.%s',
            $risk ?: 'moderado',
            $baseCurrency ?: 'ARS',
            implode(', ', $symbols),
            $note !== '' ? ' Nota del usuario: ' . $note : ''
        );

        $messages = [
            ['role' => 'system', 'content' => $systemPrompt],
            ['role' => 'user', 'content' => $userPrompt],
        ];

        $models = $this->resolveModelList($payload);
        $lastError = null;
        foreach ($models as $candidateModel) {
            try {
                $raw = $this->openRouterClient->chat($messages, $candidateModel, ['temperature' => 0.35]);
                $content = (string) ($raw['choices'][0]['message']['content'] ?? '');
                $decoded = json_decode($content, true);
                $response = [
                    'model' => $candidateModel,
                    'symbols' => $symbols,
                    'raw_text' => $content,
                    'analysis' => is_array($decoded) ? $decoded : null,
                    'cache' => false,
                ];
                $this->cache->set($cacheKey, $response, self::RADAR_ANALYSIS_TTL);
                $this->sendJson($response);
                return;
            } catch (\Throwable $e) {
                $code = (int) ($e->getCode() ?: 500);
                if (in_array($code, [429, 402])) {
                    $lastError = $e;
                    continue;
                }
                throw $e;
            }
        }
        throw $lastError ?? new \RuntimeException('No se pudo obtener respuesta del LLM', 502);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,string>
     */
    private function resolveModelList(array $payload): array
    {
        $preferred = (string) ($payload['model'] ?? '');
        $models = [];
        try {
            $models = $this->handleOpenRouterModelsInternal();
        } catch (\Throwable $e) {
            $this->logger->info('openrouter.models.fetch_failed', ['message' => $e->getMessage()]);
        }
        $ids = array_map(fn ($m) => (string) ($m['id'] ?? ''), $models);
        if ($preferred !== '' && !in_array($preferred, $ids, true)) {
            array_unshift($ids, $preferred);
        }
        if (empty($ids)) {
            $ids = [$preferred !== '' ? $preferred : 'openrouter/auto'];
        }
        return array_values(array_filter($ids, fn ($id) => $id !== ''));
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function handleOpenRouterModelsInternal(): array
    {
        $cacheFile = dirname(__DIR__, 2) . '/storage/openrouter_models.json';
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');
        if (file_exists($cacheFile)) {
            $content = json_decode((string) file_get_contents($cacheFile), true);
            if (is_array($content) && ($content['date'] ?? '') === $today && isset($content['data']) && is_array($content['data'])) {
                return $content['data'];
            }
        }
        $raw = $this->openRouterClient->listModels();
        $items = $this->filterAndSortModels($raw['data'] ?? []);
        @file_put_contents($cacheFile, json_encode(['date' => $today, 'data' => $items]));
        return $items;
    }

    /**
     * Inserta un instrumento en el portafolio del usuario, evitando duplicados.
     */
    private function handleAddInstrument(\FinHub\Domain\User\User $user): void
    {
        $data = $this->parseJsonBody();
        $especie = trim((string) ($data['especie'] ?? $data['symbol'] ?? ''));
        if ($especie === '') {
            throw new \RuntimeException('Especie requerida', 422);
        }

        $payload = [
            'especie' => $especie,
            'name' => substr(trim((string) ($data['name'] ?? '')), 0, 191),
            'exchange' => substr(trim((string) ($data['exchange'] ?? '')), 0, 64),
            'currency' => substr(trim((string) ($data['currency'] ?? '')), 0, 16),
            'country' => substr(trim((string) ($data['country'] ?? '')), 0, 64),
            'type' => substr(trim((string) ($data['type'] ?? '')), 0, 64),
            'mic_code' => substr(trim((string) ($data['mic_code'] ?? '')), 0, 16),
        ];

        $item = $this->portfolioService->addInstrument($user->getId(), $payload);

        $this->sendJson($item, 201);
    }

    /**
     * Elimina un instrumento del portafolio del usuario autenticado.
     */
    private function handleRemoveInstrument(\FinHub\Domain\User\User $user, string $symbol): void
    {
        $symbol = trim($symbol);
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        $this->portfolioService->removeInstrument($user->getId(), $symbol);

        $this->sendJson(['deleted' => true, 'symbol' => $symbol]);
    }

    private function parseJsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('JSON inválido', 400);
        }

        if (!is_array($decoded)) {
            return [];
        }

        return $decoded;
    }

    /**
     * Parsea symbols desde querystring (csv o repetidos).
     * @return array<int,string>
     */
    private function parseSymbolsQuery(): array
    {
        $symbolsParam = $_GET['symbols'] ?? '';
        $symbols = [];
        if (is_array($symbolsParam)) {
            $symbols = $symbolsParam;
        } else {
            $symbols = preg_split('/[,\\s]+/', (string) $symbolsParam, -1, PREG_SPLIT_NO_EMPTY);
        }
        $clean = [];
        foreach ($symbols as $s) {
            $v = strtoupper(trim((string) $s));
            if ($v !== '') {
                $clean[$v] = true;
            }
        }
        return array_keys($clean);
    }

    /**
     * Ejecuta un backtest y devuelve el id.
     */
    private function handleBacktestRun(\FinHub\Domain\User\User $user): void
    {
        $body = $this->parseJsonBody();
        $universe = isset($body['universe']) && is_array($body['universe']) ? array_values($body['universe']) : [];
        if (empty($universe)) {
            throw new \RuntimeException('universe requerido (array de instrumentos)', 422);
        }
        $strategyId = (string) ($body['strategy_id'] ?? 'trend_breakout');
        $startStr = (string) ($body['start'] ?? '');
        $endStr = (string) ($body['end'] ?? '');
        if ($startStr === '' || $endStr === '') {
            throw new \RuntimeException('start y end requeridos (YYYY-MM-DD)', 422);
        }
        try {
            $start = new \DateTimeImmutable($startStr);
            $end = new \DateTimeImmutable($endStr);
        } catch (\Exception $e) {
            throw new \RuntimeException('Fechas inválidas', 422);
        }
        $today = new \DateTimeImmutable('today');
        if ($end > $today) {
            $end = $today;
        }
        if ($start > $today) {
            throw new \RuntimeException('start no puede ser en el futuro', 422);
        }
        if ($end < $start) {
            throw new \RuntimeException('end debe ser mayor o igual a start', 422);
        }
        $initialCapital = (float) ($body['initial_capital'] ?? 100000);
        if ($initialCapital <= 0) {
            throw new \RuntimeException('initial_capital debe ser > 0', 422);
        }
        $request = new BacktestRequest(
            $strategyId,
            $universe,
            $start,
            $end,
            $initialCapital,
            (float) ($body['risk_per_trade_pct'] ?? 1.0),
            (float) ($body['commission_pct'] ?? 0.6),
            (float) ($body['min_fee'] ?? 0.0),
            (float) ($body['slippage_bps'] ?? 8.0),
            (float) ($body['spread_bps'] ?? 5.0),
            (int) ($body['breakout_lookback_buy'] ?? 55),
            (int) ($body['breakout_lookback_sell'] ?? 20),
            (float) ($body['atr_multiplier'] ?? 2.0),
            $user->getId()
        );
        try {
            $readiness = $this->dataReadinessService->ensureBacktestReady($universe, $start, $end, 2);
            $this->logReadinessWarnings('backtests.run', $readiness, $user->getId());
            $id = $this->backtestService->run($request);
            // Enlazar backtest a señales existentes del universo.
            try {
                $this->signalService->attachBacktestRef($universe, $id);
            } catch (\Throwable $e) {
                $this->logger->info('backtest.attach_signal.failed', [
                    'backtest_id' => $id,
                    'symbols' => $universe,
                    'message' => $e->getMessage(),
                ]);
            }
            $payload = $this->attachReadinessWarnings(['id' => $id, 'status' => 'completed'], $readiness);
            $this->sendJson($payload, 201);
        } catch (\Throwable $e) {
            $this->logger->error('backtest.run.error', [
                'user_id' => $user->getId(),
                'message' => $e->getMessage(),
            ]);
            $code = (int) ($e->getCode() ?: 500);
            $message = $e->getMessage() ?: 'No se pudo ejecutar el backtest';
            throw new \RuntimeException($message, $code);
        }
    }

    private function handleBacktestGet(int $id): void
    {
        $row = $this->backtestService->getBacktest($id);
        if ($row === null) {
            throw new \RuntimeException('Backtest no encontrado', 404);
        }
        $this->sendJson($row);
    }

    private function handleBacktestMetrics(int $id): void
    {
        $metrics = $this->backtestService->getMetrics($id);
        if ($metrics === null) {
            throw new \RuntimeException('Métricas no encontradas', 404);
        }
        $this->sendJson($metrics);
    }

    private function handleBacktestEquity(int $id): void
    {
        $equity = $this->backtestService->getEquity($id);
        $this->sendJson(['data' => $equity]);
    }

    private function handleBacktestTrades(int $id): void
    {
        $trades = $this->backtestService->getTrades($id);
        $this->sendJson(['data' => $trades]);
    }

    /**
     * Adjunta advertencias de readiness si corresponde.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    private function attachReadinessWarnings(array $payload, array $readiness): array
    {
        if (empty($readiness['warnings'])) {
            return $payload;
        }
        $payload['warnings'] = $readiness['warnings'];
        $payload['readiness'] = $this->compactReadiness($readiness);
        return $payload;
    }

    /**
     * @param array<string,mixed> $readiness
     * @return array<string,mixed>
     */
    private function compactReadiness(array $readiness): array
    {
        $keys = ['ready', 'missing', 'period', 'min_points', 'max_age_days', 'start', 'end', 'attempts'];
        $compact = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $readiness)) {
                $compact[$key] = $readiness[$key];
            }
        }
        return $compact;
    }

    /**
     * Registra en info si hubo advertencias de readiness.
     *
     * @param array<string,mixed> $readiness
     */
    private function logReadinessWarnings(string $context, array $readiness, ?int $userId = null): void
    {
        if (empty($readiness['warnings'])) {
            return;
        }
        $this->logger->info('datalake.readiness.warning', [
            'context' => $context,
            'user_id' => $userId,
            'ready' => $readiness['ready'] ?? null,
            'missing' => $readiness['missing'] ?? null,
            'warnings' => $readiness['warnings'],
            'attempts' => $readiness['attempts'] ?? null,
        ]);
    }

    // ---------------------------------------------------------------------
    // Object store (mini S3 local para R2Lite UI)
    // ---------------------------------------------------------------------
    private function sanitizeObjectKey(string $raw): string
    {
        $raw = str_replace('\\', '/', trim($raw));
        if ($raw === '') {
            throw new \RuntimeException('Key requerida', 400);
        }
        if (str_starts_with($raw, '/') || str_contains($raw, '..')) {
            throw new \RuntimeException('Key inválida', 400);
        }
        while (str_contains($raw, '//')) {
            $raw = str_replace('//', '/', $raw);
        }
        if (strlen($raw) > 240) {
            throw new \RuntimeException('Key demasiado larga', 400);
        }
        return $raw;
    }

    private function ensureObjectDir(string $path): void
    {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    private function handleObjectList(): void
    {
        $limit = isset($_GET['limit']) ? max(1, min(500, (int) $_GET['limit'])) : 100;
        $prefix = isset($_GET['prefix']) ? trim((string) $_GET['prefix']) : '';
        $items = [];
        if (!is_dir($this->objectStore)) {
            @mkdir($this->objectStore, 0775, true);
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->objectStore, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            $rel = substr($fileInfo->getPathname(), strlen($this->objectStore) + 1);
            $rel = str_replace('\\', '/', $rel);
            if ($prefix !== '' && !str_starts_with($rel, $prefix)) {
                continue;
            }
            $items[] = [
                'key' => $rel,
                'size' => $fileInfo->getSize(),
                'mime_type' => $finfo->file($fileInfo->getPathname()) ?: 'application/octet-stream',
                'etag' => md5_file($fileInfo->getPathname()) ?: '',
                'created_at' => date(DATE_ATOM, $fileInfo->getMTime()),
            ];
            if (count($items) >= $limit) {
                break;
            }
        }
        $this->sendJson(['objects' => $items, 'count' => count($items)]);
    }

    private function handleObjectHead(string $key): void
    {
        $key = $this->sanitizeObjectKey($key);
        $path = $this->objectStore . '/' . $key;
        if (!is_file($path)) {
            http_response_code(404);
            return;
        }
        http_response_code(200);
    }

    private function handleObjectGet(string $key): void
    {
        $key = $this->sanitizeObjectKey($key);
        $path = $this->objectStore . '/' . $key;
        if (!is_file($path)) {
            throw new \RuntimeException('Objeto no encontrado', 404);
        }
        $mime = mime_content_type($path) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    private function handleObjectPut(string $key): void
    {
        $key = $this->sanitizeObjectKey($key);
        $path = $this->objectStore . '/' . $key;
        if (is_file($path)) {
            throw new \RuntimeException('Objeto ya existe', 409);
        }
        $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int) $_SERVER['CONTENT_LENGTH'] : 0;
        if ($contentLength > 6 * 1024 * 1024) { // 6MB guard-rail
            throw new \RuntimeException('Payload demasiado grande', 413);
        }
        $this->ensureObjectDir($path);
        $data = file_get_contents('php://input');
        if ($data === false) {
            throw new \RuntimeException('No se pudo leer payload', 400);
        }
        $bytes = file_put_contents($path, $data);
        if ($bytes === false) {
            throw new \RuntimeException('No se pudo escribir objeto', 500);
        }
        $this->sendJson(['status' => 'created', 'key' => $key], 201);
    }

    private function handleObjectDelete(string $key): void
    {
        $key = $this->sanitizeObjectKey($key);
        $path = $this->objectStore . '/' . $key;
        if (!is_file($path)) {
            throw new \RuntimeException('Objeto no encontrado', 404);
        }
        if (!@unlink($path)) {
            throw new \RuntimeException('No se pudo borrar', 500);
        }
        http_response_code(204);
    }

    private function requireUser(): \FinHub\Domain\User\User
    {
        $payload = $this->requireAuthPayload();
        $email = (string) ($payload['email'] ?? '');
        $user = null;
        if ($email !== '') {
            $user = $this->userRepository->findByEmail($email);
        }
        if ($user === null && isset($payload['sub'])) {
            $user = $this->userRepository->findById((int) $payload['sub']);
        }
        if ($user === null) {
            throw new \RuntimeException('Token inválido', 401);
        }
        if (!$user->isActive()) {
            throw new \RuntimeException('Usuario deshabilitado', 403);
        }
        return $user;
    }

    private function requireAdmin(): \FinHub\Domain\User\User
    {
        $user = $this->requireUser();
        if (strtolower($user->getRole()) !== 'admin') {
            throw new \RuntimeException('Acceso restringido', 403);
        }
        return $user;
    }

    private function requireAuthPayload(): array
    {
        $token = $this->getBearerToken();
        try {
            return $this->jwt->decode($token);
        } catch (\InvalidArgumentException $exception) {
            throw new \RuntimeException('Token inválido', 401);
        }
    }

    private function getBearerToken(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? $_SERVER['Authorization']
            ?? $_SERVER['REDIRECT_Authorization']
            ?? '';
        if ($header === '' && function_exists('getallheaders')) {
            $headers = getallheaders();
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }
        if ($header === '' && function_exists('apache_request_headers')) {
            $headers = apache_request_headers();
            $header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
        }
        if (!is_string($header) || $header === '' || stripos($header, 'Bearer ') !== 0) {
            throw new \RuntimeException('Token requerido', 401);
        }
        return trim(substr($header, 7));
    }

    /**
     * Encapsula la lógica de CORS centralizada a partir de configuraciones.
     */
    private function sendCorsHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Access-Control-Allow-Origin: ' . ($this->config->get('CORS_ALLOWED_ORIGINS', '*')));
        header('Access-Control-Allow-Methods: GET,POST,PATCH,DELETE,OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type,Authorization,X-CRON-TOKEN');
    }

    /**
     * Loguea detalles de error cuando la ruta o método no se pueden procesar.
     */
    private function logRequestError(string $method, string $path, string $traceId, \Throwable $throwable): void
    {
        $this->logger->error('request.error', [
            'trace_id' => $traceId,
            'message' => $throwable->getMessage(),
            'path' => $path,
            'method' => $method,
        ]);
    }

    /**
     * Envía el payload de error HTTP respetando el código proporcionado.
     */
    private function sendError(\Throwable $throwable, string $traceId): void
    {
        $code = (int) $throwable->getCode();
        $status = $code >= 100 && $code < 600 ? $code : 500;
        $message = $status === 404 ? 'not_found' : 'unexpected_error';
        $method = $_SERVER['REQUEST_METHOD'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        $payload = [
            'error' => [
                'code' => $message,
                'message' => $throwable->getMessage(),
                'trace_id' => $traceId,
                'method' => $method,
                'uri' => $uri,
            ],
        ];
        $this->sendJson($payload, $status);
    }
}
