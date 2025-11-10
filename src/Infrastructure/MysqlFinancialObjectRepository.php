<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\FinancialObject;
use App\Domain\FinancialObjectRepository;
use PDO;

class MysqlFinancialObjectRepository implements FinancialObjectRepository
{
    private PDO $db;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
    }

    public function findAll(): array
    {
        $stmt = $this->db->query('SELECT id, name, symbol, type FROM financial_objects');
        $results = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $results[] = new FinancialObject($row['id'], $row['name'], $row['symbol'], $row['type']);
        }
        return $results;
    }
}
