<?php

declare(strict_types=1);

namespace App\Application;

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
}
