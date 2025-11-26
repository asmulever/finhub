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

    /**
     * @param array{
     *     level: string,
     *     http_status: int,
     *     method: string,
     *     route: string,
     *     message: string,
     *     exception_class: ?string,
     *     stack_trace: ?string,
     *     request_payload: ?array,
     *     query_params: ?array,
     *     user_id: ?int,
     *     client_ip: ?string,
     *     user_agent: ?string,
     *     correlation_id: string
     * } $record
     */
    public function store(array $record): void;
}
