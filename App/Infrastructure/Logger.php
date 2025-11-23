<?php

declare(strict_types=1);

namespace App\Infrastructure;

class Logger
{
    private string $logFile;

    public function __construct(?string $logFile = null)
    {
        $this->logFile = $this->resolveLogFile($logFile);
    }

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function error(string $message): void
    {
        $this->write('ERROR', $message);
    }

    public function warning(string $message): void
    {
        $this->write('WARNING', $message);
    }

    private function write(string $level, string $message): void
    {
        $date = date('Y-m-d H:i:s');
        $line = "[$date] [$level] $message" . PHP_EOL;

        file_put_contents($this->logFile, $line, FILE_APPEND);
    }

    private function resolveLogFile(?string $configuredPath): string
    {
        $projectRoot = dirname(__DIR__, 2);
        $logsDir = $projectRoot . '/logs';

        if (!is_dir($logsDir)) {
            @mkdir($logsDir, 0775, true);
        }

        if (is_string($configuredPath) && $configuredPath !== '') {
            return $configuredPath;
        }

        $dailyFile = $logsDir . '/app-' . date('Y-m-d') . '.log';
        if (!is_writable($logsDir)) {
            $fallbackDir = sys_get_temp_dir();
            $dailyFile = $fallbackDir . '/app-' . date('Y-m-d') . '.log';
        }

        return $dailyFile;
    }
}
