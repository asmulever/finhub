<?php
namespace FinHub\Infrastructure\Logging;

interface LoggerInterface
{
    public function log(string $level, string $message, array $context = []): void;

    public function info(string $message, array $context = []): void;

    public function error(string $message, array $context = []): void;
}
