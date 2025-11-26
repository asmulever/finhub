<?php

declare(strict_types=1);

namespace App\Domain\Repository;

interface LogRepositoryInterface
{
    /**
     * @param array<string,mixed> $filters
     * @return array{data: array<int,array<string,mixed>>, total: int}
     */
    public function paginate(array $filters, int $page, int $pageSize): array;

    /**
     * @return array<string,mixed>|null
     */
    public function findById(int $id): ?array;
}
