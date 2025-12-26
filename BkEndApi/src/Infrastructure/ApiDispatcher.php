<?php
declare(strict_types=1);

namespace FinHub\Infrastructure;

use FinHub\Application\Auth\AuthService;
use FinHub\Application\MarketData\Dto\PriceRequest;
use FinHub\Application\MarketData\PriceService;
use FinHub\Application\MarketData\ProviderUsageService;
use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Logging\LoggerInterface;
use FinHub\Infrastructure\MarketData\EodhdClient;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;
use PDO;

final class ApiDispatcher
{
    private Config $config;
    private LoggerInterface $logger;
    private AuthService $authService;
    private PriceService $priceService;
    private UserRepositoryInterface $userRepository;
    private JwtTokenProvider $jwt;
    private PasswordHasher $passwordHasher;
    private PDO $pdo;
    private EodhdClient $eodhdClient;
    private ProviderUsageService $providerUsage;
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
        \PDO $pdo,
        EodhdClient $eodhdClient,
        ProviderUsageService $providerUsage
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
        $this->pdo = $pdo;
        $this->eodhdClient = $eodhdClient;
        $this->providerUsage = $providerUsage;
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
            $exchange = trim((string) ($_GET['exchange'] ?? 'US'));
            $stocks = $this->priceService->listStocks($exchange === '' ? 'US' : $exchange);
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
            $symbols = $this->getPortfolioSymbols();
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
            $items = $this->listPortfolioInstruments($user->getId());
            $this->sendJson(['data' => $items]);
            return;
        }
        if ($method === 'GET' && $path === '/portfolios') {
            $user = $this->requireUser();
            $items = $this->listPortfolios($user->getId());
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
        $this->ensureDataLakeTables();
        $symbols = $this->getPortfolioSymbols();
        if (empty($symbols)) {
            throw new \RuntimeException('No hay símbolos configurados para ingesta', 400);
        }
        $startedAt = microtime(true);
        $results = [
            'started_at' => date('c', (int) $startedAt),
            'finished_at' => null,
            'total_symbols' => count($symbols),
            'ok' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($symbols as $symbol) {
            $snapshot = $this->fetchPriceFromProvider($symbol);
            $stored = $this->storeSnapshot($snapshot);
            if ($stored['success']) {
                $results['ok']++;
            } else {
                $results['failed']++;
                $results['errors'][] = ['symbol' => $symbol, 'reason' => $stored['reason']];
            }
        }

        $results['finished_at'] = date('c');
        // Si todas las consultas fallaron, marcar error 500; si hay símbolos pero ninguno, ya se lanzó 400 arriba.
        $status = $results['failed'] === $results['total_symbols'] ? 500 : 200;
        $this->sendHtml('tarea ejecutada', $status);
    }

    /**
     * Devuelve la serie temporal para un símbolo en un período.
     */
    private function handlePriceSeries(): void
    {
        $this->ensureDataLakeTables();
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        $period = trim((string) ($_GET['period'] ?? '1m'));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $since = $this->resolveSince($period);
        $params = [':symbol' => $symbol];
        $where = 'symbol = :symbol';
        if ($since !== null) {
            $where .= ' AND as_of >= :since';
            $params[':since'] = $since->format('Y-m-d H:i:s.u');
        }
        $query = sprintf('SELECT as_of, payload_json FROM dl_price_snapshots WHERE %s ORDER BY as_of ASC', $where);
        $stmt = $this->pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $points = [];
        foreach ($rows ?: [] as $row) {
            $payload = $row['payload_json'];
            if (is_string($payload)) {
                $payload = json_decode($payload, true);
            }
            if (!is_array($payload)) {
                continue;
            }
            $price = $this->extractPrice($payload);
            if ($price === null) {
                continue;
            }
            $asOfIso = (new \DateTimeImmutable((string) $row['as_of']))->format(\DateTimeInterface::ATOM);
            $points[] = [
                't' => $asOfIso,
                'price' => $price,
            ];
        }
        $this->sendJson([
            'symbol' => $symbol,
            'period' => $period,
            'points' => $points,
        ]);
    }

    /**
     * Devuelve el último precio almacenado en dl_price_latest para un símbolo.
     */
    private function handleLatestPrice(): void
    {
        $this->ensureDataLakeTables();
        $symbol = trim((string) ($_GET['symbol'] ?? ''));
        if ($symbol === '') {
            throw new \RuntimeException('symbol requerido', 422);
        }
        $query = 'SELECT symbol, provider, as_of, payload_json FROM dl_price_latest WHERE symbol = :symbol LIMIT 1';
        $stmt = $this->pdo->prepare($query);
        $stmt->execute([':symbol' => $symbol]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException('Precio no disponible en Data Lake', 404);
        }
        $payload = $row['payload_json'];
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }
        if (!is_array($payload)) {
            throw new \RuntimeException('Payload inválido en Data Lake', 500);
        }
        $quote = $this->normalizeSnapshotPayload($payload, $symbol, (string) $row['provider'], (string) $row['as_of']);
        $this->sendJson($quote);
    }

    /**
     * Obtiene lista deduplicada de símbolos desde portfolio_instruments.
     */
    private function getPortfolioSymbols(): array
    {
        $sql = 'SELECT DISTINCT symbol FROM portfolio_instruments WHERE symbol IS NOT NULL AND symbol <> \'\' ORDER BY symbol ASC';
        $stmt = $this->pdo->query($sql);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_values(array_filter(array_map(static fn ($r) => (string) $r['symbol'], $rows ?: [])));
    }

    /**
     * Ejecuta consulta a proveedor externo (Twelve Data) sin reutilizar clases existentes.
     */
    private function fetchPriceFromProvider(string $symbol): array
    {
        $snapshot = $this->priceService->fetchSnapshot($symbol);
        $asOfString = $snapshot['as_of'] ?? null;
        $asOf = $asOfString ? new \DateTimeImmutable((string) $asOfString) : new \DateTimeImmutable();
        return [
            'symbol' => $snapshot['symbol'] ?? $symbol,
            'provider' => $snapshot['source'] ?? 'unknown',
            'payload' => $snapshot['payload'] ?? $snapshot,
            'as_of' => $asOf,
            'http_status' => $snapshot['http_status'] ?? null,
            'error_code' => $snapshot['error_code'] ?? null,
            'error_msg' => $snapshot['error_msg'] ?? null,
        ];
    }

    /**
     * Inserta snapshot y actualiza última versión.
     */
    private function storeSnapshot(array $snapshot): array
    {
        // No persistir registros con código de error informado
        if (isset($snapshot['error_code']) && $snapshot['error_code'] !== null && $snapshot['error_code'] !== '') {
            return ['success' => false, 'reason' => sprintf('Error del proveedor: %s', $snapshot['error_code'])];
        }

        try {
            $payloadJson = json_encode($snapshot['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $hash = hash('sha256', $payloadJson, true);
            $asOf = $snapshot['as_of'] instanceof \DateTimeInterface ? $snapshot['as_of']->format('Y-m-d H:i:s.u') : date('Y-m-d H:i:s.u');

            $insert = <<<'SQL'
INSERT IGNORE INTO dl_price_snapshots (symbol, provider, as_of, payload_json, payload_hash, http_status, error_code, error_msg)
VALUES (:symbol, :provider, :as_of, :payload_json, :payload_hash, :http_status, :error_code, :error_msg)
SQL;
            $stmt = $this->pdo->prepare($insert);
            $stmt->execute([
                'symbol' => $snapshot['symbol'],
                'provider' => $snapshot['provider'],
                'as_of' => $asOf,
                'payload_json' => $payloadJson,
                'payload_hash' => $hash,
                'http_status' => $snapshot['http_status'] ?? null,
                'error_code' => $snapshot['error_code'] ?? null,
                'error_msg' => $snapshot['error_msg'] ?? null,
            ]);

            $upsert = <<<'SQL'
INSERT INTO dl_price_latest (symbol, provider, as_of, payload_json)
VALUES (:symbol, :provider, :as_of, :payload_json)
ON DUPLICATE KEY UPDATE
    as_of = IF(VALUES(as_of) > as_of, VALUES(as_of), as_of),
    payload_json = IF(VALUES(as_of) > as_of, VALUES(payload_json), payload_json),
    updated_at = NOW(6)
SQL;
            $uStmt = $this->pdo->prepare($upsert);
            $uStmt->execute([
                'symbol' => $snapshot['symbol'],
                'provider' => $snapshot['provider'],
                'as_of' => $asOf,
                'payload_json' => $payloadJson,
            ]);

            return ['success' => true];
        } catch (\Throwable $e) {
            $this->logger->error('datalake.store.error', [
                'symbol' => $snapshot['symbol'] ?? '',
                'message' => $e->getMessage(),
            ]);
            return ['success' => false, 'reason' => $e->getMessage()];
        }
    }

    private function extractPrice(array $payload): ?float
    {
        $candidates = [
            $payload['close'] ?? null,
            $payload['price'] ?? null,
            $payload['c'] ?? null,
        ];
        foreach ($candidates as $value) {
            if (is_numeric($value)) {
                return (float) $value;
            }
        }
        return null;
    }

    /**
     * Normaliza un snapshot persistido para exponerlo como quote.
     */
    private function normalizeSnapshotPayload(array $payload, string $symbol, string $provider, string $asOf): array
    {
        $close = $payload['close'] ?? $payload['price'] ?? $payload['c'] ?? null;
        $open = $payload['open'] ?? $payload['o'] ?? null;
        $high = $payload['high'] ?? $payload['h'] ?? null;
        $low = $payload['low'] ?? $payload['l'] ?? null;
        $previousClose = $payload['previous_close'] ?? $payload['previousClose'] ?? $payload['pc'] ?? null;
        $currency = $payload['currency'] ?? $payload['currency_code'] ?? null;
        $name = $payload['name'] ?? $payload['symbol'] ?? null;
        $asOfValue = $payload['as_of'] ?? $payload['datetime'] ?? $payload['timestamp'] ?? $payload['date'] ?? $asOf;

        return [
            'symbol' => $payload['symbol'] ?? $symbol,
            'name' => $name,
            'currency' => $currency,
            'close' => $close !== null ? (float) $close : null,
            'open' => $open !== null ? (float) $open : null,
            'high' => $high !== null ? (float) $high : null,
            'low' => $low !== null ? (float) $low : null,
            'previous_close' => $previousClose !== null ? (float) $previousClose : null,
            'asOf' => $asOfValue,
            'source' => $provider,
        ];
    }

    private function resolveSince(string $period): ?\DateTimeImmutable
    {
        $now = new \DateTimeImmutable();
        return match ($period) {
            '1m' => $now->modify('-1 month'),
            '3m' => $now->modify('-3 months'),
            '6m' => $now->modify('-6 months'),
            '1y' => $now->modify('-12 months'),
            default => null,
        };
    }

    /**
     * Crea tablas del data lake si no existen.
     */
    private function ensureDataLakeTables(): void
    {
        $this->pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS dl_price_snapshots (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  symbol VARCHAR(32) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  as_of DATETIME(6) NOT NULL,
  payload_json JSON NOT NULL,
  payload_hash BINARY(32) NOT NULL,
  http_status SMALLINT UNSIGNED NULL,
  error_code VARCHAR(64) NULL,
  error_msg VARCHAR(255) NULL,
  created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  UNIQUE KEY uniq_snapshot (symbol, provider, as_of, payload_hash),
  INDEX idx_symbol_provider_asof (symbol, provider, as_of),
  INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );

        $this->pdo->exec(
            <<<'SQL'
CREATE TABLE IF NOT EXISTS dl_price_latest (
  symbol VARCHAR(32) NOT NULL,
  provider VARCHAR(32) NOT NULL DEFAULT 'twelvedata',
  as_of DATETIME(6) NOT NULL,
  payload_json JSON NOT NULL,
  updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  PRIMARY KEY (symbol)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
        );
    }

    /**
     * Devuelve todos los instrumentos del portafolio del usuario autenticado.
     */
    private function listPortfolioInstruments(int $userId): array
    {
        $portfolioId = $this->ensureUserPortfolio($userId);
        $query = <<<'SQL'
SELECT id, symbol, name, exchange, currency, country, type, mic_code
FROM portfolio_instruments
WHERE portfolio_id = :portfolio_id
ORDER BY symbol ASC
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute(['portfolio_id' => $portfolioId]);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'symbol' => (string) ($row['symbol'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'exchange' => (string) ($row['exchange'] ?? ''),
                'currency' => (string) ($row['currency'] ?? ''),
                'country' => (string) ($row['country'] ?? ''),
                'type' => (string) ($row['type'] ?? ''),
                'mic_code' => (string) ($row['mic_code'] ?? ''),
            ];
        }, $rows ?: []);
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

        $portfolioId = $this->ensureUserPortfolio($user->getId());
        $payload = [
            'portfolio_id' => $portfolioId,
            'symbol' => $symbol,
            'name' => substr(trim((string) ($data['name'] ?? '')), 0, 191),
            'exchange' => substr(trim((string) ($data['exchange'] ?? '')), 0, 64),
            'currency' => substr(trim((string) ($data['currency'] ?? '')), 0, 16),
            'country' => substr(trim((string) ($data['country'] ?? '')), 0, 64),
            'type' => substr(trim((string) ($data['type'] ?? '')), 0, 64),
            'mic_code' => substr(trim((string) ($data['mic_code'] ?? '')), 0, 16),
        ];

        $insert = <<<'SQL'
INSERT INTO portfolio_instruments (portfolio_id, symbol, name, exchange, currency, country, type, mic_code)
VALUES (:portfolio_id, :symbol, :name, :exchange, :currency, :country, :type, :mic_code)
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    exchange = VALUES(exchange),
    currency = VALUES(currency),
    country = VALUES(country),
    type = VALUES(type),
    mic_code = VALUES(mic_code)
SQL;
        $statement = $this->pdo->prepare($insert);
        $statement->execute($payload);

        $select = $this->pdo->prepare('SELECT id, symbol, name, exchange, currency, country, type, mic_code FROM portfolio_instruments WHERE portfolio_id = :portfolio_id AND symbol = :symbol LIMIT 1');
        $select->execute(['portfolio_id' => $portfolioId, 'symbol' => $symbol]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        $item = $row ? [
            'id' => (int) $row['id'],
            'symbol' => (string) $row['symbol'],
            'name' => (string) ($row['name'] ?? ''),
            'exchange' => (string) ($row['exchange'] ?? ''),
            'currency' => (string) ($row['currency'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'mic_code' => (string) ($row['mic_code'] ?? ''),
        ] : [];

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

        $portfolioId = $this->ensureUserPortfolio($user->getId());
        $delete = $this->pdo->prepare('DELETE FROM portfolio_instruments WHERE portfolio_id = :portfolio_id AND symbol = :symbol');
        $delete->execute([
            'portfolio_id' => $portfolioId,
            'symbol' => $symbol,
        ]);

        $this->sendJson(['deleted' => true, 'symbol' => $symbol]);
    }

    /**
     * Devuelve la lista de portafolios del usuario (al menos uno garantizado).
     */
    private function listPortfolios(int $userId): array
    {
        // Garantiza que exista el portafolio principal
        $this->ensureUserPortfolio($userId);
        $select = $this->pdo->prepare(
            'SELECT id, name, base_currency, created_at, updated_at FROM portfolios WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY id ASC'
        );
        $select->execute(['user_id' => $userId]);
        $rows = $select->fetchAll(\PDO::FETCH_ASSOC) ?: [];
        return array_map(static function (array $row): array {
            return [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?? ''),
                'base_currency' => (string) ($row['base_currency'] ?? 'USD'),
                'created_at' => $row['created_at'] ?? null,
                'updated_at' => $row['updated_at'] ?? null,
            ];
        }, $rows);
    }

    /**
     * Garantiza la existencia de un portafolio por usuario y devuelve su ID.
     */
    private function ensureUserPortfolio(int $userId): int
    {
        $select = $this->pdo->prepare('SELECT id FROM portfolios WHERE user_id = :user_id LIMIT 1');
        $select->execute(['user_id' => $userId]);
        $row = $select->fetch(\PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['id'];
        }

        $insert = $this->pdo->prepare('INSERT INTO portfolios (user_id, name) VALUES (:user_id, :name)');
        $insert->execute(['user_id' => $userId, 'name' => 'default']);
        return (int) $this->pdo->lastInsertId();
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
