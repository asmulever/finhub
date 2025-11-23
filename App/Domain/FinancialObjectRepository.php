<?php

declare(strict_types=1);

namespace App\Domain;

interface FinancialObjectRepository
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
