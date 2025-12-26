<?php
declare(strict_types=1);

namespace FinHub\Infrastructure;

use FinHub\Application\Auth\AuthService;
use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\PriceService;
use FinHub\Application\MarketData\ProviderUsageService;
use FinHub\Application\Portfolio\PortfolioService;
use FinHub\Application\DataLake\DataLakeService;
use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;

final class ApiDispatcher
{
    private Config $config;
    private LoggerInterface $logger;
    private AuthService $authService;
    private PriceService $priceService;
    private UserRepositoryInterface $userRepository;
    private JwtTokenProvider $jwt;
    private PasswordHasher $passwordHasher;
    private EodhdClient $eodhdClient;
    private ProviderUsageService $providerUsage;
    private PortfolioService $portfolioService;
    private DataLakeService $dataLakeService;
    /** Rutas base deben terminar sin barra final. */
    private string $apiBase;

    public function __construct(
        Config $config,
        LoggerInterface $logger,
        AuthService $authService,
        PriceService $priceService,
        UserRepositoryInterface $userRepository,
        JwtTokenProvider $jwt,
        PasswordHasher $passwordHasher,
        EodhdClient $eodhdClient,
        ProviderUsageService $providerUsage,
        PortfolioService $portfolioService,
        DataLakeService $dataLakeService
    )
    {
        $this->config = $config;
        $this->logger = $logger;
        $this->apiBase = rtrim($config->get('API_BASE_PATH', '/api'), '/');
        $this->authService = $authService;
        $this->priceService = $priceService;
        $this->userRepository = $userRepository;
        $this->jwt = $jwt;
        $this->passwordHasher = $passwordHasher;
        $this->eodhdClient = $eodhdClient;
        $this->providerUsage = $providerUsage;
        $this->portfolioService = $portfolioService;
        $this->dataLakeService = $dataLakeService;
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
        if ($method === 'GET' && ($path === '/prices' || $path === '/quotes')) {
            $request = PriceRequest::fromArray($_GET ?? []);
            $quote = $this->priceService->getPrice($request);
            $this->sendJson($quote);
            return;
        }
        if ($method === 'POST' && $path === '/datalake/prices/collect') {
            $this->handleCollectPrices($traceId);
            return;
        }
        if ($method === 'GET' && $path === '/datalake/prices/symbols') {
            $symbols = $this->portfolioService->listSymbols();
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
        if ($method === 'GET' && $path === '/portfolio/instruments') {
            $user = $this->requireUser();
            $items = $this->portfolioService->listInstruments($user->getId());
            $this->sendJson(['data' => $items]);
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
                $this->handleDeleteUser($userId);
                return;
            }
        }
        if ($method === 'POST' && $path === '/auth/login') {
            $this->handleLogin();
            return;
        }
        if ($method === 'POST' && $path === '/auth/register') {
            $this->handleRegister();
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

    private function handleRegister(): void
    {
        $data = $this->parseJsonBody();
        $email = trim((string) ($data['email'] ?? ''));
        $password = (string) ($data['password'] ?? '');

        if ($email === '' || $password === '') {
            throw new \RuntimeException('Email y contraseña requeridos', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email inválido', 422);
        }
        if (strlen($password) < 6) {
            throw new \RuntimeException('La contraseña debe tener al menos 6 caracteres', 422);
        }

        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null) {
            throw new \RuntimeException('Email ya registrado', 409);
        }

        $hash = $this->passwordHasher->hash($password);
        $user = $this->userRepository->create($email, 'user', 'disabled', $hash);
        $this->sendJson($user->toResponse(), 201);
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

    private function handleDeleteUser(int $userId): void
    {
        $deleted = $this->userRepository->delete($userId);
        if (!$deleted) {
            throw new \RuntimeException('Usuario no encontrado', 404);
        }
        $this->sendJson(['deleted' => true]);
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

    /**
     * Lanza la recolección de precios para todos los símbolos de portafolios y guarda snapshots.
     * Endpoint público sin auth por requerimiento.
     */
    private function handleCollectPrices(string $traceId): void
    {
        $symbols = $this->portfolioService->listSymbols();
        if (empty($symbols)) {
            throw new \RuntimeException('No hay símbolos configurados para ingesta', 400);
        }
        $results = $this->dataLakeService->collect($symbols);
        $status = $results['failed'] === $results['total_symbols'] ? 500 : 200;
        $this->sendJson($results, $status);
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
     * Devuelve el último precio almacenado en dl_price_latest para un símbolo.
     */
    private function handleLatestPrice(): void
    {
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $quote = $this->dataLakeService->latestQuote($symbol);
        $this->sendJson($quote);
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
