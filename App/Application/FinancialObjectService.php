<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\FinancialObject;
use App\Domain\FinancialObjectRepository;
use App\Infrastructure\Logger;

class FinancialObjectService
{
    private Logger $logger;

    public function __construct(private readonly FinancialObjectRepository $financialObjectRepository)
    {
        $this->logger = new Logger();
    }

    public function getAllFinancialObjects(): array
    {
        $this->logger->info("Fetching all financial objects.");
        try {
            $objects = $this->financialObjectRepository->findAll();
            $this->logger->info("Successfully fetched " . count($objects) . " financial objects.");
            return array_map(fn($object) => $object->toArray(), $objects);
        } catch (\Exception $e) {
            $this->logger->error("Error fetching financial objects: " . $e->getMessage());
            return [];
        }
    }

    public function createFinancialObject(array $data): ?array
    {
        $name = trim($data['name'] ?? '');
        $symbol = trim($data['symbol'] ?? '');
        $type = trim($data['type'] ?? '');

        if ($name === '' || $symbol === '' || $type === '') {
            $this->logger->warning('Validation failed while creating financial object.');
            return null;
        }

        try {
            $object = new FinancialObject(null, $name, strtoupper($symbol), strtolower($type));
            $id = $this->financialObjectRepository->save($object);
            return [
                'id' => $id,
                'name' => $name,
                'symbol' => strtoupper($symbol),
                'type' => strtolower($type),
            ];
        } catch (\Exception $e) {
            $this->logger->error("Error creating financial object: " . $e->getMessage());
            return null;
        }
    }

    public function updateFinancialObject(int $id, array $data): bool
    {
        $existing = $this->financialObjectRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to update non-existing financial object $id");
            return false;
        }

        $name = trim($data['name'] ?? '');
        $symbol = trim($data['symbol'] ?? '');
        $type = trim($data['type'] ?? '');

        if ($name === '' || $symbol === '' || $type === '') {
            $this->logger->warning("Validation failed while updating financial object $id");
            return false;
        }

        try {
            $updated = new FinancialObject($id, $name, strtoupper($symbol), strtolower($type));
            $this->financialObjectRepository->update($updated);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error updating financial object $id: " . $e->getMessage());
            return false;
        }
    }

    public function deleteFinancialObject(int $id): bool
    {
        $existing = $this->financialObjectRepository->findById($id);
        if ($existing === null) {
            $this->logger->warning("Attempted to delete non-existing financial object $id");
            return false;
        }

        try {
            $this->financialObjectRepository->delete($id);
            return true;
        } catch (\Exception $e) {
            $this->logger->error("Error deleting financial object $id: " . $e->getMessage());
            return false;
        }
    }
}
