<?php

declare(strict_types=1);

namespace App\Infrastructure;

use App\Domain\FinancialObject;
use App\Domain\FinancialObjectRepository;
use PDO;

class MysqlFinancialObjectRepository implements FinancialObjectRepository
{
    private PDO $db;
    private Logger $logger;

    public function __construct()
    {
        $this->db = DatabaseConnection::getInstance();
        $this->logger = new Logger();
    }

    public function findAll(): array
    {
        $this->logger->info("Attempting to find all financial objects");
        try {
            $stmt = $this->db->query('SELECT id, name, symbol, type FROM financial_objects');
            $results = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $results[] = new FinancialObject((int)$row['id'], $row['name'], $row['symbol'], $row['type']);
            }
            $this->logger->info("Found " . count($results) . " financial objects.");
            return $results;
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding all financial objects: " . $e->getMessage());
            return [];
        }
    }

    public function findById(int $id): ?FinancialObject
    {
        $this->logger->info("Attempting to find financial object by id: $id");
        try {
            $stmt = $this->db->prepare('SELECT id, name, symbol, type FROM financial_objects WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $id]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                $this->logger->info("Financial object with id $id not found.");
                return null;
            }

            return new FinancialObject((int)$row['id'], $row['name'], $row['symbol'], $row['type']);
        } catch (\PDOException $e) {
            $this->logger->error("Database error while finding financial object by id: " . $e->getMessage());
            return null;
        }
    }

    public function save(FinancialObject $financialObject): int
    {
        $this->logger->info("Attempting to save financial object: " . $financialObject->getName());
        try {
            $stmt = $this->db->prepare('INSERT INTO financial_objects (name, symbol, type) VALUES (:name, :symbol, :type)');
            $stmt->execute([
                'name' => $financialObject->getName(),
                'symbol' => $financialObject->getSymbol(),
                'type' => $financialObject->getType()
            ]);
            $this->logger->info("Financial object saved successfully.");
            return (int)$this->db->lastInsertId();
        } catch (\PDOException $e) {
            $this->logger->error("Database error while saving financial object: " . $e->getMessage());
            throw $e;
        }
    }

    public function update(FinancialObject $financialObject): void
    {
        $this->logger->info("Attempting to update financial object: " . $financialObject->getId());
        try {
            $stmt = $this->db->prepare('UPDATE financial_objects SET name = :name, symbol = :symbol, type = :type WHERE id = :id');
            $stmt->execute([
                'id' => $financialObject->getId(),
                'name' => $financialObject->getName(),
                'symbol' => $financialObject->getSymbol(),
                'type' => $financialObject->getType()
            ]);
            $this->logger->info("Financial object updated successfully.");
        } catch (\PDOException $e) {
            $this->logger->error("Database error while updating financial object: " . $e->getMessage());
            throw $e;
        }
    }

    public function delete(int $id): void
    {
        $this->logger->info("Attempting to delete financial object: $id");
        try {
            $stmt = $this->db->prepare('DELETE FROM financial_objects WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $this->logger->info("Financial object deleted successfully.");
        } catch (\PDOException $e) {
            $this->logger->error("Database error while deleting financial object: " . $e->getMessage());
            throw $e;
        }
    }
}
