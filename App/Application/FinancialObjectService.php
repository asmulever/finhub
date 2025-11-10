<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\FinancialObjectRepository;

class FinancialObjectService
{
    public function __construct(private readonly FinancialObjectRepository $financialObjectRepository)
    {
    }

    public function getAllFinancialObjects(): array
    {
        $objects = $this->financialObjectRepository->findAll();
        return array_map(fn($object) => $object->toArray(), $objects);
    }
}
