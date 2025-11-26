<?php

declare(strict_types=1);

namespace App\Application;

use App\Domain\Repository\LogRepositoryInterface;

class LogService
{
    public function __construct(private readonly LogRepositoryInterface $logRepository)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function getLogs(array $filters, int $page, int $pageSize): array
    {
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));
        $result = $this->logRepository->paginate($filters, $page, $pageSize);

        return [
            'data' => $result['data'],
            'pagination' => [
                'page' => $page,
                'page_size' => $pageSize,
                'total' => $result['total'],
                'total_pages' => (int)ceil(($result['total'] ?: 1) / $pageSize),
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    public function getLogById(int $id): ?array
    {
        return $this->logRepository->findById($id);
    }
}
