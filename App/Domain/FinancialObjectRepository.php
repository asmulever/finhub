<?php

declare(strict_types=1);

namespace App\Domain;

interface FinancialObjectRepository
{
    /**
     * @return FinancialObject[]
     */
    public function findAll(): array;
}
