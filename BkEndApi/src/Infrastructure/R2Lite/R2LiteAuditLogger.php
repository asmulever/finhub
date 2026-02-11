<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\R2Lite;

/**
 * Logger simple para auditoría de pedidos R2Lite. Escribe JSONL en disco.
 */
final class R2LiteAuditLogger
{
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
    }

    /**
     * @param array<string,mixed> $data
     */
    public function write(array $data): void
    {
        $payload = $data;
        $payload['ts'] = $payload['ts'] ?? (new \DateTimeImmutable())->format(DATE_ATOM);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            return;
        }
        @file_put_contents($this->path, $json . "\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * Lee las últimas N líneas del log (tail).
     *
     * @return array<int,array<string,mixed>>
     */
    public function tail(int $limit = 200): array
    {
        if (!is_file($this->path)) {
            return [];
        }
        $lines = [];
        $fp = fopen($this->path, 'rb');
        if ($fp === false) {
            return [];
        }
        $buffer = '';
        $pos = -1;
        while (count($lines) < $limit && fseek($fp, $pos, SEEK_END) === 0) {
            $char = fgetc($fp);
            if ($char === "\n") {
                if ($buffer !== '') {
                    $line = strrev($buffer);
                    $decoded = json_decode($line, true);
                    if (is_array($decoded)) {
                        $lines[] = $decoded;
                    }
                    $buffer = '';
                }
            } elseif ($char !== false) {
                $buffer .= $char;
            }
            $pos--;
            if (ftell($fp) === 0) {
                // Primera línea
                $buffer = strrev($buffer);
                if ($buffer !== '') {
                    $decoded = json_decode($buffer, true);
                    if (is_array($decoded)) {
                        $lines[] = $decoded;
                    }
                }
                break;
            }
        }
        fclose($fp);
        return array_slice(array_reverse($lines), 0, $limit);
    }
}
