<?php
namespace FinHub\Infrastructure\Logging;

final class FileLogger implements LoggerInterface
{
    private string $logDirectory;
    private string $level;

    public function __construct(string $logDirectory, string $level = 'info')
    {
        $this->logDirectory = rtrim($logDirectory, '/');
        $this->level = $level;
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0775, true);
        }
    }

    public function log(string $level, string $message, array $context = []): void
    {
        $timestamp = (new \DateTimeImmutable())->format('Y-m-d H:i:s');
        $context = $this->normalizeContext($context);
        $line = sprintf("[%s] %s: %s %s", $timestamp, strtoupper($level), $message, $context);
        error_log($line . PHP_EOL, 3, $this->buildPath());
    }

    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    private function buildPath(): string
    {
        $filename = sprintf('app-%s.log', (new \DateTimeImmutable())->format('Y-m-d'));
        return $this->logDirectory . '/' . $filename;
    }

    private function normalizeContext(array $context): string
    {
        if (empty($context)) {
            return '';
        }
        return json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
