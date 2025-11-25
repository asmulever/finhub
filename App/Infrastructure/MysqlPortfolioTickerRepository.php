<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\PortfolioTickerRepository;
use PDO;

class MysqlPortfolioTickerRepository implements PortfolioTickerRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function findDetailedByBroker(int $brokerId, int $userId): array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.account_id, t.financial_object_id, t.quantity, t.avg_price,
                    f.name AS financial_object_name, f.symbol AS financial_object_symbol, f.type AS financial_object_type
             FROM portfolio_tickers t
             INNER JOIN accounts a ON a.id = t.account_id
             INNER JOIN financial_objects f ON f.id = t.financial_object_id
             WHERE t.account_id = :account_id AND a.user_id = :user_id
             ORDER BY t.id DESC'
        );
        $stmt->execute([
            'account_id' => $brokerId,
            'user_id' => $userId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function findDetailedById(int $tickerId, int $userId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT t.id, t.account_id, t.financial_object_id, t.quantity, t.avg_price,
                    f.name AS financial_object_name, f.symbol AS financial_object_symbol, f.type AS financial_object_type
             FROM portfolio_tickers t
             INNER JOIN accounts a ON a.id = t.account_id
             INNER JOIN financial_objects f ON f.id = t.financial_object_id
             WHERE t.id = :ticker_id AND a.user_id = :user_id
             LIMIT 1'
        );
        $stmt->execute([
            'ticker_id' => $tickerId,
            'user_id' => $userId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    public function create(int $brokerId, int $financialObjectId, float $quantity, float $avgPrice, int $userId): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO portfolio_tickers (account_id, financial_object_id, quantity, avg_price)
             SELECT :account_id, :financial_object_id, :quantity, :avg_price
             FROM accounts a
             WHERE a.id = :account_check AND a.user_id = :user_id'
        );
        $stmt->execute([
            'account_id' => $brokerId,
            'financial_object_id' => $financialObjectId,
            'quantity' => $quantity,
            'avg_price' => $avgPrice,
            'account_check' => $brokerId,
            'user_id' => $userId,
        ]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Unauthorized portfolio access.');
        }

        return (int)$this->db->lastInsertId();
    }

    public function update(int $tickerId, float $quantity, float $avgPrice, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE portfolio_tickers t
             INNER JOIN accounts a ON a.id = t.account_id
             SET t.quantity = :quantity, t.avg_price = :avg_price
             WHERE t.id = :ticker_id AND a.user_id = :user_id'
        );
        $stmt->execute([
            'quantity' => $quantity,
            'avg_price' => $avgPrice,
            'ticker_id' => $tickerId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $tickerId, int $userId): bool
    {
        $stmt = $this->db->prepare(
            'DELETE t FROM portfolio_tickers t
             INNER JOIN accounts a ON a.id = t.account_id
             WHERE t.id = :ticker_id AND a.user_id = :user_id'
        );
        $stmt->execute([
            'ticker_id' => $tickerId,
            'user_id' => $userId,
        ]);

        return $stmt->rowCount() > 0;
    }
}
