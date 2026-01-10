<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Portfolio;

use FinHub\Application\Portfolio\PortfolioRepositoryInterface;
use PDO;

final class PdoPortfolioRepository implements PortfolioRepositoryInterface
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function ensureUserPortfolio(int $userId): int
    {
        $select = $this->pdo->prepare('SELECT id FROM portfolios WHERE user_id = :user_id LIMIT 1');
        $select->execute(['user_id' => $userId]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        if ($row !== false) {
            return (int) $row['id'];
        }
        $insert = $this->pdo->prepare('INSERT INTO portfolios (user_id, name, base_currency) VALUES (:user_id, :name, :base_currency)');
        $insert->execute(['user_id' => $userId, 'name' => 'default', 'base_currency' => 'USD']);
        return (int) $this->pdo->lastInsertId();
    }

    public function listInstruments(int $portfolioId): array
    {
        $query = <<<'SQL'
SELECT id, symbol, name, exchange, currency, country, type, mic_code
FROM portfolio_instruments
WHERE portfolio_id = :portfolio_id
ORDER BY symbol ASC
SQL;
        $statement = $this->pdo->prepare($query);
        $statement->execute(['portfolio_id' => $portfolioId]);
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC) ?: [];
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
        }, $rows);
    }

    public function addInstrument(int $portfolioId, array $payload): array
    {
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
        $statement->execute([
            'portfolio_id' => $portfolioId,
            'symbol' => $payload['symbol'],
            'name' => $payload['name'] ?? '',
            'exchange' => $payload['exchange'] ?? '',
            'currency' => $payload['currency'] ?? '',
            'country' => $payload['country'] ?? '',
            'type' => $payload['type'] ?? '',
            'mic_code' => $payload['mic_code'] ?? '',
        ]);

        $select = $this->pdo->prepare('SELECT id, symbol, name, exchange, currency, country, type, mic_code FROM portfolio_instruments WHERE portfolio_id = :portfolio_id AND symbol = :symbol LIMIT 1');
        $select->execute(['portfolio_id' => $portfolioId, 'symbol' => $payload['symbol']]);
        $row = $select->fetch(PDO::FETCH_ASSOC);
        return $row ? [
            'id' => (int) $row['id'],
            'symbol' => (string) $row['symbol'],
            'name' => (string) ($row['name'] ?? ''),
            'exchange' => (string) ($row['exchange'] ?? ''),
            'currency' => (string) ($row['currency'] ?? ''),
            'country' => (string) ($row['country'] ?? ''),
            'type' => (string) ($row['type'] ?? ''),
            'mic_code' => (string) ($row['mic_code'] ?? ''),
        ] : [];
    }

    public function removeInstrument(int $portfolioId, string $symbol): bool
    {
        $delete = $this->pdo->prepare('DELETE FROM portfolio_instruments WHERE portfolio_id = :portfolio_id AND symbol = :symbol');
        $delete->execute([
            'portfolio_id' => $portfolioId,
            'symbol' => $symbol,
        ]);
        return true;
    }

    public function listPortfolios(int $userId): array
    {
        $sql = 'SELECT id, name, base_currency, created_at, updated_at FROM portfolios WHERE user_id = :user_id AND deleted_at IS NULL ORDER BY id ASC';
        try {
            $select = $this->pdo->prepare($sql);
            $select->execute(['user_id' => $userId]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (\Throwable $e) {
            // Fallback para esquemas sin columna deleted_at
            $fallback = 'SELECT id, name, base_currency, created_at, updated_at FROM portfolios WHERE user_id = :user_id ORDER BY id ASC';
            $select = $this->pdo->prepare($fallback);
            $select->execute(['user_id' => $userId]);
            $rows = $select->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }
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

    public function listSymbols(?int $userId = null): array
    {
        $baseSql = 'SELECT DISTINCT pi.symbol FROM portfolio_instruments pi';
        $params = [];
        if ($userId !== null) {
            $baseSql .= ' INNER JOIN portfolios p ON pi.portfolio_id = p.id AND p.user_id = :user_id';
            $params[':user_id'] = $userId;
        }
        $baseSql .= ' WHERE pi.symbol IS NOT NULL AND pi.symbol <> \'\' ORDER BY pi.symbol ASC';
        $stmt = $this->pdo->prepare($baseSql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_values(array_filter(array_map(static fn ($r) => (string) $r['symbol'], $rows ?: [])));
    }

    public function getBaseCurrency(int $portfolioId): string
    {
        $stmt = $this->pdo->prepare('SELECT base_currency FROM portfolios WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $portfolioId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $currency = $row['base_currency'] ?? 'USD';
        $currency = trim((string) $currency);
        return $currency === '' ? 'USD' : $currency;
    }
}
