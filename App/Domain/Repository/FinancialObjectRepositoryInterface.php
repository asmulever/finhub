<?php

declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\FinancialObject;

interface FinancialObjectRepositoryInterface
{
    /**
     * @return FinancialObject[]
     */
    public function findAll(): array;

    public function findById(int $id): ?FinancialObject;

    public function save(FinancialObject $financialObject): int;

    public function update(FinancialObject $financialObject): void;

    public function delete(int $id): void;
}
