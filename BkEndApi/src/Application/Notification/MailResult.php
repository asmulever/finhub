<?php
declare(strict_types=1);

namespace FinHub\Application\Notification;

/**
 * Resultado de envío de correo transaccional.
 */
final class MailResult
{
    private int $statusCode;
    private string $body;

    public function __construct(int $statusCode, string $body)
    {
        $this->statusCode = $statusCode;
        $this->body = $body;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function isSuccess(): bool
    {
        return in_array($this->statusCode, [200, 201, 202], true);
    }
}
