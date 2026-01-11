<?php
declare(strict_types=1);

namespace FinHub\Infrastructure;

use FinHub\Application\Auth\AuthService;
use FinHub\Application\Auth\ActivationService;
use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\PriceService;
use FinHub\Application\MarketData\ProviderUsageService;
use FinHub\Application\MarketData\PolygonService;
use FinHub\Application\MarketData\TiingoService;
use FinHub\Application\MarketData\StooqService;
use FinHub\Application\MarketData\RavaCedearsService;
use FinHub\Application\MarketData\RavaAccionesService;
use FinHub\Application\MarketData\RavaBonosService;
use FinHub\Application\MarketData\RavaHistoricosService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Application\Portfolio\PortfolioSummaryService;
use FinHub\Application\Portfolio\PortfolioSectorService;
use FinHub\Application\Portfolio\PortfolioHeatmapService;
use FinHub\Application\Analytics\PredictionService;
use FinHub\Application\DataLake\DataLakeService;
use FinHub\Application\DataLake\InstrumentCatalogService;
use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;
use FinHub\Infrastructure\User\UserDeletionService;

final class ApiDispatcher
{
    private Config $config;
    private LoggerInterface $logger;
    private AuthService $authService;
    private ActivationService $activationService;
    private PriceService $priceService;
    private UserRepositoryInterface $userRepository;
    private JwtTokenProvider $jwt;
    private PasswordHasher $passwordHasher;
    private EodhdClient $eodhdClient;
    private ProviderUsageService $providerUsage;
    private PortfolioService $portfolioService;
    private PortfolioSummaryService $portfolioSummaryService;
    private PortfolioSectorService $portfolioSectorService;
    private PortfolioHeatmapService $portfolioHeatmapService;
    private PredictionService $predictionService;
    private DataLakeService $dataLakeService;
    private InstrumentCatalogService $instrumentCatalogService;
    private PolygonService $polygonService;
    private TiingoService $tiingoService;
    private StooqService $stooqService;
    private RavaCedearsService $ravaCedearsService;
    private RavaAccionesService $ravaAccionesService;
    private RavaBonosService $ravaBonosService;
    private RavaHistoricosService $ravaHistoricosService;
    private UserDeletionService $userDeletionService;
    /** Rutas base deben terminar sin barra final. */
    private string $apiBase;

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        AuthService $authService,
        ActivationService $activationService,
        PriceService $priceService,
        UserRepositoryInterface $userRepository,
        JwtTokenProvider $jwt,
        PasswordHasher $passwordHasher,
        EodhdClient $eodhdClient,
        ProviderUsageService $providerUsage,
        PortfolioService $portfolioService,
        PortfolioSummaryService $portfolioSummaryService,
        PortfolioSectorService $portfolioSectorService,
        PortfolioHeatmapService $portfolioHeatmapService,
        PredictionService $predictionService,
        DataLakeService $dataLakeService,
        InstrumentCatalogService $instrumentCatalogService,
        PolygonService $polygonService,
        TiingoService $tiingoService,
        StooqService $stooqService,
        RavaCedearsService $ravaCedearsService,
        RavaAccionesService $ravaAccionesService,
        RavaBonosService $ravaBonosService,
        RavaHistoricosService $ravaHistoricosService,
        UserDeletionService $userDeletionService
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->apiBase = rtrim($config->get('API_BASE_PATH', '/api'), '/');
        $this->authService = $authService;
        $this->activationService = $activationService;
        $this->priceService = $priceService;
        $this->userRepository = $userRepository;
        $this->jwt = $jwt;
        $this->passwordHasher = $passwordHasher;
        $this->eodhdClient = $eodhdClient;
        $this->providerUsage = $providerUsage;
        $this->portfolioService = $portfolioService;
        $this->portfolioSummaryService = $portfolioSummaryService;
        $this->portfolioSectorService = $portfolioSectorService;
        $this->portfolioHeatmapService = $portfolioHeatmapService;
        $this->predictionService = $predictionService;
        $this->dataLakeService = $dataLakeService;
        $this->instrumentCatalogService = $instrumentCatalogService;
        $this->polygonService = $polygonService;
        $this->tiingoService = $tiingoService;
        $this->stooqService = $stooqService;
        $this->ravaCedearsService = $ravaCedearsService;
        $this->ravaAccionesService = $ravaAccionesService;
        $this->ravaBonosService = $ravaBonosService;
        $this->ravaHistoricosService = $ravaHistoricosService;
        $this->userDeletionService = $userDeletionService;
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
        if ($method === 'GET' && $path === '/status') {
            $this->sendJson([
                'status' => 'ok',
                'env' => $this->config->get('APP_ENV', 'production'),
                'trace_id' => $traceId,
            ]);
            return;
        }
        if ($method === 'GET' && $path === '/rava/cedears') {
            $this->handleRavaCedears();
            return;
        }
        if ($method === 'GET' && $path === '/rava/acciones') {
            $this->handleRavaAcciones();
            return;
        }
        if ($method === 'GET' && $path === '/rava/bonos') {
            $this->handleRavaBonos();
            return;
        }
        if ($method === 'GET' && $path === '/rava/historicos') {
            $this->handleRavaHistoricos();
            return;
        }
        if ($method === 'GET' && $path === '/stocks') {
            $exchange = trim((string) ($_GET['exchange'] ?? 'BA'));
            try {
                $stocks = $this->priceService->listStocks($exchange === '' ? 'BA' : $exchange);
            } catch (\Throwable $e) {
                $this->logger->error('stocks.error', [
                    'trace_id' => $traceId,
                    'exchange' => $exchange,
                    'message' => $e->getMessage(),
                ]);
                throw new \RuntimeException('No se pudo obtener el listado de instrumentos', 502);
            }
            $this->sendJson(['data' => $stocks]);
            return;
        }
        if ($method === 'GET' && $path === '/me') {
            $user = $this->requireUser();
            $this->sendJson($user->toResponse());
            return;
        }
        if ($method === 'GET' && $path === '/metrics/providers') {
            $this->requireUser();
            $metrics = $this->providerUsage->getUsage();
            $this->sendJson($metrics);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/alphavantage/')) {
            $this->requireAdmin();
            $this->handleAlphaVantage($path);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/eodhd/')) {
            $this->requireAdmin();
            $this->handleEodhdAdmin($path);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/twelvedata/')) {
            $this->requireAdmin();
            $this->handleTwelveData($path);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/polygon/')) {
            $this->requireAdmin();
            $this->handlePolygon($path);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/tiingo/')) {
            $this->requireAdmin();
            $this->handleTiingo($path);
            return;
        }
        if ($method === 'GET' && str_starts_with($path, '/stooq/')) {
            $this->requireAdmin();
            $this->handleStooq($path);
            return;
        }
        if ($method === 'GET' && ($path === '/prices' || $path === '/quotes')) {
            $request = PriceRequest::fromArray($_GET ?? []);
            $quote = $this->priceService->getPrice($request);
            $this->sendJson($quote);
            return;
        }
        if ($method === 'GET' && $path === '/quote/search/bulk') {
            $this->handleQuoteSearchBulk();
            return;
        }
        if ($method === 'GET' && $path === '/quote/search') {
            $this->handleQuoteSearch();
            return;
        }
        if ($method === 'GET' && $path === '/quote/symbols') {
            $this->handleQuoteSymbols();
            return;
        }
        if ($method === 'POST' && $path === '/datalake/prices/collect') {
            $this->handleCollectPrices($traceId);
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
        if ($method === 'GET' && $path === '/eodhd/eod') {
            $this->requireAdmin();
            $this->handleEodhdEod();
            return;
        }
        if ($method === 'GET' && $path === '/eodhd/exchange-symbols') {
            $this->requireAdmin();
            $this->handleEodhdExchangeSymbols();
            return;
        }
        if ($method === 'GET' && $path === '/eodhd/exchanges-list') {
            $this->requireAdmin();
            $this->handleEodhdExchangesList();
            return;
        }
        if ($method === 'GET' && $path === '/eodhd/user') {
            $this->requireAdmin();
            $data = $this->eodhdClient->fetchUser();
            $this->sendJson(['data' => $data]);
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
            $data = $this->portfolioHeatmapService->build($user->getId());
            $this->sendJson($data);
            return;
        }
        if ($method === 'GET' && $path === '/portfolio/sector-industry') {
            $user = $this->requireUser();
            $data = $this->portfolioSectorService->listSectorIndustry($user->getId());
            $this->sendJson(['data' => $data]);
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
     * Consulta EODHD EOD para un símbolo (solo Admin).
     */
    private function handleEodhdEod(): void
    {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $data = $this->eodhdClient->fetchEod($symbol);
        $this->sendJson(['symbol' => $symbol, 'data' => $data]);
    }

    /**
     * Lista símbolos de un exchange en EODHD (solo Admin).
     */
    private function handleEodhdExchangeSymbols(): void
    {
        $exchange = trim((string) ($_GET['exchange'] ?? 'US'));
        if ($exchange === '') {
            throw new \RuntimeException('exchange requerido', 422);
        }
        $data = $this->eodhdClient->fetchExchangeSymbols($exchange);
        $this->sendJson(['exchange' => $exchange, 'data' => $data]);
    }

    /**
     * Lista exchanges disponibles en EODHD (solo Admin).
     */
    private function handleEodhdExchangesList(): void
    {
        $data = $this->eodhdClient->fetchExchangesList();
        $this->sendJson(['data' => $data]);
    }

    private function handleEodhdAdmin(string $path): void
    {
        if ($path === '/eodhd/eod') {
            $symbol = trim((string) ($_GET['symbol'] ?? ''));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->eodhdClient->fetchEod($symbol);
            $this->sendJson(['symbol' => $symbol, 'data' => $data]);
            return;
        }
        if ($path === '/eodhd/search') {
            $query = trim((string) ($_GET['q'] ?? ($_GET['query'] ?? '')));
            if ($query === '') {
                throw new \RuntimeException('q requerido', 422);
            }
            $parts = array_values(array_filter(array_map(static fn ($s) => trim((string) $s), explode(',', $query)), static fn ($s) => $s !== ''));
            $results = [];
            $errors = [];
            foreach ($parts as $q) {
                try {
                    $data = $this->eodhdClient->search($q);
                    if (is_array($data)) {
                        $results = array_merge($results, $data);
                    }
                } catch (\Throwable $e) {
                    $errors[] = ['query' => $q, 'message' => $e->getMessage()];
                }
            }
            if (empty($results) && !empty($errors)) {
                $first = $errors[0];
                throw new \RuntimeException(sprintf('No se obtuvieron resultados: %s', $first['message']), 502);
            }
            $this->sendJson(['data' => $results]);
            return;
        }
        if ($path === '/eodhd/exchange-symbols') {
            $this->handleEodhdExchangeSymbols();
            return;
        }
        if ($path === '/eodhd/exchanges-list') {
            $this->handleEodhdExchangesList();
            return;
        }
        if ($path === '/eodhd/user') {
            $data = $this->eodhdClient->fetchUser();
            $this->sendJson(['data' => $data]);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    /**
     * Lanza la recolección de precios para todos los símbolos de portafolios y guarda snapshots.
     * Endpoint público sin auth por requerimiento.
     */
    private function handleCollectPrices(string $traceId): void
    {
        $symbols = [];
        $body = $this->parseJsonBody();
        if (isset($body['symbols']) && is_array($body['symbols'])) {
            $symbols = array_values(array_filter(array_map('strval', $body['symbols'])));
        }
        if (empty($symbols)) {
            $symbols = $this->portfolioService->listSymbols();
        }
        if (empty($symbols)) {
            throw new \RuntimeException('No hay símbolos configurados para ingesta', 400);
        }
        $this->logger->info('datalake.collect.request', [
            'trace_id' => $traceId,
            'symbols_count' => count($symbols),
        ]);
        $results = $this->dataLakeService->collect($symbols);
        $this->logger->info('datalake.collect.summary', [
            'trace_id' => $traceId,
            'ok' => $results['ok'],
            'failed' => $results['failed'],
            'total' => $results['total_symbols'],
            'status' => $results['failed'] === $results['total_symbols'] ? 'failed' : ($results['failed'] > 0 ? 'partial' : 'ok'),
        ]);
        $status = $results['failed'] === $results['total_symbols'] ? 500 : 200;
        $this->sendJson($results, $status);
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
     * Lista CEDEARs obtenidos desde RAVA (cache + stale).
     */
    private function handleRavaCedears(): void
    {
        $result = $this->ravaCedearsService->listCedears();
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'meta' => $result['meta'] ?? [],
        ]);
    }

    /**
     * Lista Acciones Argentinas desde RAVA (agrupadas/normalizadas).
     */
    private function handleRavaAcciones(): void
    {
        $result = $this->ravaAccionesService->listAcciones();
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'meta' => $result['meta'] ?? [],
        ]);
    }

    /**
     * Lista Bonos desde RAVA.
     */
    private function handleRavaBonos(): void
    {
        $result = $this->ravaBonosService->listBonos();
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'meta' => $result['meta'] ?? [],
        ]);
    }

    /**
     * Devuelve histórico diario de una especie desde RAVA.
     */
    private function handleRavaHistoricos(): void
    {
        $especie = trim((string) ($_GET['especie'] ?? ($_GET['symbol'] ?? '')));
        if ($especie === '') {
            throw new \RuntimeException('Parámetro especie requerido', 422);
        }
        $result = $this->ravaHistoricosService->historicos($especie);
        $this->sendJson([
            'data' => $result['items'] ?? [],
            'meta' => $result['meta'] ?? [],
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
        // Intentar histórico de RAVA como fuente principal
        try {
            $result = $this->ravaHistoricosService->historicos($symbol);
            $items = $result['items'] ?? $result['data'] ?? [];
            $this->sendJson([
                'symbol' => $symbol,
                'period' => $period,
                'points' => $items,
                'source' => 'rava',
            ]);
            return;
        } catch (\Throwable $e) {
            $this->logger->info('datalake.series.rava_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
        }
        // Fallback: proveedor directo
        $series = $this->dataLakeService->series($symbol, $period);
        $this->sendJson($series);
    }

    /**
     * Busca un precio en proveedores externos (EODHD/TwelveData) con fallback y cache.
     */
    private function handleQuoteSearchBulk(): void
    {
        $rawSymbols = $_GET['s'] ?? '';
        $symbols = [];
        if (is_array($rawSymbols)) {
            foreach ($rawSymbols as $chunk) {
                $parts = preg_split('/,/', (string) $chunk, -1, PREG_SPLIT_NO_EMPTY);
                if (is_array($parts)) {
                    foreach ($parts as $p) {
                        $symbols[] = strtoupper(trim($p));
                    }
                }
            }
        } else {
            $parts = preg_split('/,/', (string) $rawSymbols, -1, PREG_SPLIT_NO_EMPTY);
            if (is_array($parts)) {
                foreach ($parts as $p) {
                    $symbols[] = strtoupper(trim($p));
                }
            }
        }

        $symbols = array_values(array_filter(array_unique($symbols), static fn ($s) => $s !== ''));
        if (empty($symbols)) {
            throw new \RuntimeException('Parámetro s (symbols) requerido', 422);
        }

        $exchange = isset($_GET['ex']) ? strtoupper(trim((string) $_GET['ex'])) : null;
        $preferred = strtolower(trim((string) ($_GET['preferred'] ?? 'twelvedata')));
        $force = isset($_GET['force']) && (string) $_GET['force'] === '1';

        $quotes = $this->priceService->searchQuotes($symbols, $exchange, $preferred, $force);
        $this->sendJson(['data' => $quotes]);
    }

    /**
     * Endpoints de prueba para Alpha Vantage (solo admin).
     */
    private function handleAlphaVantage(string $path): void
    {
        if ($path === '/alphavantage/quote') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $quote = $this->priceService->alphaQuote($symbol);
            $this->sendJson(['data' => $quote]);
            return;
        }
        if ($path === '/alphavantage/search') {
            $keywords = trim((string) ($_GET['keywords'] ?? ''));
            if ($keywords === '') {
                throw new \RuntimeException('keywords requerido', 422);
            }
            $result = $this->priceService->alphaSearch($keywords);
            $this->sendJson(['data' => $result]);
            return;
        }
        if ($path === '/alphavantage/daily') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $outputSize = strtolower(trim((string) ($_GET['outputsize'] ?? 'compact')));
            $data = $this->priceService->alphaDaily($symbol, $outputSize === 'full' ? 'full' : 'compact');
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/alphavantage/intraday') {
            throw new \RuntimeException('Endpoint no disponible', 404);
        }
        if ($path === '/alphavantage/fx-intraday') {
            throw new \RuntimeException('Endpoint no disponible', 404);
        }
        if ($path === '/alphavantage/fx-daily') {
            $from = strtoupper(trim((string) ($_GET['from'] ?? '')));
            $to = strtoupper(trim((string) ($_GET['to'] ?? '')));
            if ($from === '' || $to === '') {
                throw new \RuntimeException('from/to requeridos', 422);
            }
            $data = $this->priceService->alphaFxDaily($from, $to);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/alphavantage/crypto-intraday') {
            throw new \RuntimeException('Endpoint no disponible', 404);
        }
        if ($path === '/alphavantage/sma') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $interval = strtolower(trim((string) ($_GET['interval'] ?? 'daily')));
            $timePeriod = (int) ($_GET['time_period'] ?? 20);
            $seriesType = strtolower(trim((string) ($_GET['series_type'] ?? 'close')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->alphaSma($symbol, $interval, $timePeriod, $seriesType);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/alphavantage/rsi') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $interval = strtolower(trim((string) ($_GET['interval'] ?? 'daily')));
            $timePeriod = (int) ($_GET['time_period'] ?? 14);
            $seriesType = strtolower(trim((string) ($_GET['series_type'] ?? 'close')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->alphaRsi($symbol, $interval, $timePeriod, $seriesType);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/alphavantage/overview') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->alphaOverview($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    private function handleTwelveData(string $path): void
    {
        if ($path === '/twelvedata/time_series') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $interval = (string) ($_GET['interval'] ?? '1day');
            $outputsize = (string) ($_GET['outputsize'] ?? 'compact');
            $data = $this->priceService->twelveTimeSeries($symbol, [
                'interval' => $interval,
                'outputsize' => $outputsize,
            ]);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/quote') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->twelveQuote($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/price') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->twelvePrice($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/quotes') {
            $symbolsParam = (string) ($_GET['symbols'] ?? '');
            $symbols = array_filter(array_map(static fn ($s) => strtoupper(trim((string) $s)), explode(',', $symbolsParam)), static fn ($s) => $s !== '');
            if (empty($symbols)) {
                throw new \RuntimeException('symbols requeridos', 422);
            }
            $data = $this->priceService->twelveQuotes($symbols);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/stocks') {
            $exchange = trim((string) ($_GET['exchange'] ?? ''));
            $data = $this->priceService->twelveStocks($exchange === '' ? null : $exchange);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/stocks/by-exchange') {
            $exchange = trim((string) ($_GET['exchange'] ?? ''));
            if ($exchange === '') {
                throw new \RuntimeException('exchange requerido', 422);
            }
            $data = $this->priceService->twelveStocksByExchange($exchange);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/usage') {
            $data = $this->priceService->twelveUsage();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/exchanges') {
            $data = $this->priceService->twelveExchanges();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/exchange_rate') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->twelveExchangeRate($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/currency_conversion') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $amount = (float) ($_GET['amount'] ?? 0);
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            if ($amount <= 0) {
                throw new \RuntimeException('amount debe ser mayor a 0', 422);
            }
            $data = $this->priceService->twelveCurrencyConversion($symbol, $amount);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/market_state') {
            $data = $this->priceService->twelveMarketState();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/cryptocurrency_exchanges') {
            $data = $this->priceService->twelveCryptoExchanges();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/instrument_type') {
            $data = $this->priceService->twelveInstrumentTypes();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/symbol_search') {
            $keywords = trim((string) ($_GET['symbol'] ?? ''));
            if ($keywords === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->twelveSymbolSearch($keywords);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/forex_pairs') {
            $data = $this->priceService->twelveForexPairs();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/cryptocurrencies') {
            $data = $this->priceService->twelveCryptocurrencies();
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/earliest_timestamp') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $exchange = isset($_GET['exchange']) ? strtoupper(trim((string) $_GET['exchange'])) : null;
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->priceService->twelveEarliestTimestamp($symbol, $exchange);
            $this->sendJson(['data' => $data]);
            return;
        }

        if ($path === '/twelvedata/technical_indicator') {
            $function = trim((string) ($_GET['function'] ?? ''));
            $symbol = trim((string) ($_GET['symbol'] ?? ''));
            $interval = trim((string) ($_GET['interval'] ?? '1day'));
            if ($function === '' || $symbol === '') {
                throw new \RuntimeException('function y symbol requeridos', 422);
            }
            $params = $_GET;
            $params['symbol'] = $symbol;
            $params['interval'] = $interval;
            $data = $this->priceService->twelveTechnicalIndicator($function, $params);
            $this->sendJson(['data' => $data]);
            return;
        }

        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    private function handlePolygon(string $path): void
    {
        if ($path === '/polygon/tickers') {
            $query = [
                'ticker' => trim((string) ($_GET['ticker'] ?? '')),
                'search' => trim((string) ($_GET['search'] ?? '')),
                'active' => isset($_GET['active']) ? (string) $_GET['active'] : null,
                'market' => trim((string) ($_GET['market'] ?? '')),
                'type' => trim((string) ($_GET['type'] ?? '')),
                'locale' => trim((string) ($_GET['locale'] ?? 'us')),
                'limit' => (int) ($_GET['limit'] ?? 20),
                'order' => trim((string) ($_GET['order'] ?? 'asc')),
                'sort' => trim((string) ($_GET['sort'] ?? 'ticker')),
            ];
            $data = $this->polygonService->listTickers($query);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/ticker-details') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->tickerDetails($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/aggregates') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $multiplier = (int) ($_GET['multiplier'] ?? 1);
            $timespan = trim((string) ($_GET['timespan'] ?? 'day'));
            $from = trim((string) ($_GET['from'] ?? ''));
            $to = trim((string) ($_GET['to'] ?? ''));
            $sort = trim((string) ($_GET['sort'] ?? 'desc'));
            $limit = (int) ($_GET['limit'] ?? 120);
            $adjusted = !isset($_GET['adjusted']) || (string) $_GET['adjusted'] !== '0';
            if ($symbol === '' || $from === '' || $to === '') {
                throw new \RuntimeException('symbol, from y to son requeridos', 422);
            }
            $data = $this->polygonService->aggregates($symbol, max(1, $multiplier), $timespan, $from, $to, $adjusted, $sort, $limit);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/previous-close') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $adjusted = !isset($_GET['adjusted']) || (string) $_GET['adjusted'] !== '0';
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->previousClose($symbol, $adjusted);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/daily-open-close') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $date = trim((string) ($_GET['date'] ?? ''));
            $adjusted = !isset($_GET['adjusted']) || (string) $_GET['adjusted'] !== '0';
            if ($symbol === '' || $date === '') {
                throw new \RuntimeException('symbol y date requeridos', 422);
            }
            $data = $this->polygonService->dailyOpenClose($symbol, $date, $adjusted);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/grouped-daily') {
            $date = trim((string) ($_GET['date'] ?? ''));
            if ($date === '') {
                throw new \RuntimeException('date requerido', 422);
            }
            $market = trim((string) ($_GET['market'] ?? 'stocks'));
            $locale = trim((string) ($_GET['locale'] ?? 'us'));
            $adjusted = !isset($_GET['adjusted']) || (string) $_GET['adjusted'] !== '0';
            $data = $this->polygonService->groupedDaily($date, $market === '' ? 'stocks' : $market, $locale === '' ? 'us' : $locale, $adjusted);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/last-trade') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->lastTrade($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/last-quote') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->lastQuote($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/snapshot') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $market = trim((string) ($_GET['market'] ?? 'stocks'));
            $locale = trim((string) ($_GET['locale'] ?? 'us'));
            $data = $this->polygonService->snapshot($symbol, $market === '' ? 'stocks' : $market, $locale === '' ? 'us' : $locale);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/news') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? 10);
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->news($symbol, max(1, min($limit, 50)));
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/dividends') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? 50);
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->dividends($symbol, max(1, min($limit, 100)));
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/splits') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            $limit = (int) ($_GET['limit'] ?? 50);
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->polygonService->splits($symbol, max(1, min($limit, 100)));
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/exchanges') {
            $asset = trim((string) ($_GET['asset_class'] ?? ($_GET['asset'] ?? 'stocks')));
            $locale = trim((string) ($_GET['locale'] ?? ''));
            $data = $this->polygonService->exchanges($asset === '' ? 'stocks' : $asset, $locale === '' ? null : $locale);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/polygon/market-status') {
            $data = $this->polygonService->marketStatus();
            $this->sendJson(['data' => $data]);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    private function handleTiingo(string $path): void
    {
        if ($path === '/tiingo/iex/tops') {
            $tickers = $this->splitSymbols((string) ($_GET['tickers'] ?? ''));
            $data = $this->tiingoService->iexTops($tickers);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/iex/last') {
            $tickers = $this->splitSymbols((string) ($_GET['tickers'] ?? ''));
            $data = $this->tiingoService->iexLast($tickers);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/daily/prices') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $query = [
                'startDate' => trim((string) ($_GET['startDate'] ?? '')),
                'endDate' => trim((string) ($_GET['endDate'] ?? '')),
                'resampleFreq' => trim((string) ($_GET['resampleFreq'] ?? '')),
            ];
            $data = $this->tiingoService->dailyPrices($symbol, $query);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/daily/meta') {
            $symbol = strtoupper(trim((string) ($_GET['symbol'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $data = $this->tiingoService->dailyMetadata($symbol);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/crypto/prices') {
            $tickers = $this->splitSymbols((string) ($_GET['tickers'] ?? ''));
            $query = [
                'startDate' => trim((string) ($_GET['startDate'] ?? '')),
                'endDate' => trim((string) ($_GET['endDate'] ?? '')),
                'resampleFreq' => trim((string) ($_GET['resampleFreq'] ?? '')),
            ];
            $data = $this->tiingoService->cryptoPrices($tickers, $query);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/fx/prices') {
            $tickers = $this->splitSymbols((string) ($_GET['tickers'] ?? ''));
            $query = [
                'startDate' => trim((string) ($_GET['startDate'] ?? '')),
                'endDate' => trim((string) ($_GET['endDate'] ?? '')),
                'resampleFreq' => trim((string) ($_GET['resampleFreq'] ?? '')),
            ];
            $data = $this->tiingoService->fxPrices($tickers, $query);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/search') {
            $query = trim((string) ($_GET['query'] ?? ''));
            if ($query === '') {
                throw new \RuntimeException('query requerido', 422);
            }
            $data = $this->tiingoService->search($query);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/tiingo/news') {
            $tickers = $this->splitSymbols((string) ($_GET['tickers'] ?? ''));
            if (empty($tickers)) {
                throw new \RuntimeException('tickers requerido', 422);
            }
            $query = [
                'startDate' => trim((string) ($_GET['startDate'] ?? '')),
                'endDate' => trim((string) ($_GET['endDate'] ?? '')),
                'limit' => (int) ($_GET['limit'] ?? 10),
                'source' => trim((string) ($_GET['source'] ?? '')),
            ];
            $data = $this->tiingoService->news($tickers, $query);
            $this->sendJson(['data' => $data]);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    private function handleStooq(string $path): void
    {
        if ($path === '/stooq/quotes') {
            $tickers = $this->splitSymbols((string) ($_GET['symbols'] ?? ($_GET['s'] ?? '')));
            if (empty($tickers)) {
                throw new \RuntimeException('symbols requerido', 422);
            }
            $data = $this->stooqService->quotes($tickers);
            $this->sendJson(['data' => $data]);
            return;
        }
        if ($path === '/stooq/history') {
            $symbol = strtolower(trim((string) ($_GET['symbol'] ?? '')));
            $market = strtolower(trim((string) ($_GET['market'] ?? '')));
            if ($symbol === '') {
                throw new \RuntimeException('symbol requerido', 422);
            }
            $interval = strtolower(trim((string) ($_GET['interval'] ?? 'd')));
            $symbolWithMarket = $market !== '' ? sprintf('%s.%s', $symbol, $market) : $symbol;
            $data = $this->stooqService->history($symbolWithMarket, $interval);
            $this->sendJson(['data' => $data, 'symbol' => $symbolWithMarket]);
            return;
        }
        if ($path === '/stooq/markets') {
            $data = $this->stooqService->markets();
            $this->sendJson(['data' => $data]);
            return;
        }
        throw new \RuntimeException('Ruta no encontrada', 404);
    }

    /**
     * Busca un precio en proveedores externos (EODHD/TwelveData) con fallback y cache.
     */
    private function handleQuoteSearch(): void
    {
        $symbol = strtoupper(trim((string) ($_GET['s'] ?? '')));
        $exchange = isset($_GET['ex']) ? strtoupper(trim((string) $_GET['ex'])) : null;
        $preferred = strtolower(trim((string) ($_GET['preferred'] ?? 'twelvedata')));
        $force = isset($_GET['force']) && (string) $_GET['force'] === '1';

        if ($symbol === '') {
            throw new \RuntimeException('Parámetro s (symbol) requerido', 422);
        }
        if ($preferred !== 'eodhd' && $preferred !== 'twelvedata' && $preferred !== 'alphavantage') {
            $preferred = 'twelvedata';
        }

        $quote = $this->priceService->searchQuote($symbol, $exchange, $preferred, $force);
        $this->sendJson($quote);
    }

    /**
     * Lista unificada de símbolos (EODHD + TwelveData) para un exchange.
     */
    private function handleQuoteSymbols(): void
    {
        $exchange = strtoupper(trim((string) ($_GET['exchange'] ?? '')));
        if ($exchange === '') {
            throw new \RuntimeException('Parámetro exchange requerido', 422);
        }
        $symbols = $this->priceService->listSymbols($exchange);
        $this->sendJson(['data' => $symbols]);
    }

    /**
     * Devuelve el último precio disponible para un símbolo (catálogo fresco o proveedor).
     */
    private function handleLatestPrice(): void
    {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }

        // 1) Intentar Data Lake primero (snapshot más reciente).
        try {
            $quote = $this->dataLakeService->latestQuote($symbol);
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
            return;
        } catch (\Throwable $e) {
            $this->logger->info('datalake.latest.dl_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
        }

        // 2) Último recurso: proveedor directo.
        try {
            $price = $this->priceService->getPrice(new PriceRequest($symbol));
            $this->sendJson($price);
        } catch (\Throwable $e) {
            $this->logger->info('datalake.latest.provider_failed', [
                'symbol' => $symbol,
                'message' => $e->getMessage(),
            ]);
            throw $e;
        }
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
        $result = $this->predictionService->runForUser($user->getId());
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
     * Inserta un instrumento en el portafolio del usuario, evitando duplicados.
     */
    private function handleAddInstrument(\FinHub\Domain\User\User $user): void
    {
        $data = $this->parseJsonBody();
        $symbol = trim((string) ($data['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('Símbolo requerido', 422);
        }

        $payload = [
            'symbol' => $symbol,
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
     * Normaliza símbolos separados por comas.
     *
     * @return array<int,string>
     */
    private function splitSymbols(string $input): array
    {
        $symbols = [];
        $parts = preg_split('/,/', $input, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($parts)) {
            foreach ($parts as $p) {
                $symbols[] = strtoupper(trim($p));
            }
        }
        return array_values(array_filter(array_unique($symbols), static fn ($s) => $s !== ''));
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
        $payload = [
            'error' => [
                'code' => $message,
                'message' => $throwable->getMessage(),
                'trace_id' => $traceId,
            ],
        ];
        $this->sendJson($payload, $status);
    }
}
