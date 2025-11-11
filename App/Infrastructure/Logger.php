<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Logger
{
    private const LOG_FILE = __DIR__ . '/../../../logs/app.log';

    public function __construct()
    {
        $logDir = dirname(self::LOG_FILE);
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
    }

    public function info(string $message): void
    {
        $this->log('INFO', $message);
    }

    public function warning(string $message): void
    {
        $this->log('WARNING', $message);
    }

    public function error(string $message): void
    {
        $this->log('ERROR', $message);
    }

    private function log(string $level, string $message): void
    {
        $timestamp = date('c'); // ISO 8601 format
        $logEntry = sprintf("[%s] [%s] %s\n", $timestamp, $level, $message);
        file_put_contents(self::LOG_FILE, $logEntry, FILE_APPEND);
    }
}
