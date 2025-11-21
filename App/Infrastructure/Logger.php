<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Logger
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        // En InfinityFree: /tmp/ siempre es escribible
        $this->logFile = $logFile ?? '/tmp/app.log';
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    private function write(string $level, string $message): void
    {
        $date = date('Y-m-d H:i:s');
        $line = "[$date] [$level] $message" . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND);
    }
}
