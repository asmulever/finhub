<?php

declare(strict_types=1);

namespace App\Domain;

class EtlRun
{
    public function __construct(
        private readonly ?int $id,
        private readonly string $jobName,
        private readonly string $startedAt,
        private readonly ?string $finishedAt,
        private readonly string $status,
        private readonly int $rowsAffected,
        private readonly ?string $message,
        private readonly ?string $createdAt = null,
    ) {
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getJobName(): string
    {
        return $this->jobName;
    }

    public function getStartedAt(): string
    {
        return $this->startedAt;
    }

    public function getFinishedAt(): ?string
    {
        return $this->finishedAt;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getRowsAffected(): int
    {
        return $this->rowsAffected;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function getCreatedAt(): ?string
    {
        return $this->createdAt;
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'job_name' => $this->jobName,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'status' => $this->status,
            'rows_affected' => $this->rowsAffected,
            'message' => $this->message,
            'created_at' => $this->createdAt,
        ];
    }
}

