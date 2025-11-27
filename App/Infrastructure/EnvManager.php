<?php

declare(strict_types=1);

namespace App\Infrastructure;

class EnvManager
{
    private string $envPath;

    public function __construct(?string $envPath = null)
    {
        $this->envPath = $envPath ?? dirname(__DIR__, 2) . '/.env';
        if (!file_exists($this->envPath)) {
            throw new \RuntimeException('.env file not found at ' . $this->envPath);
        }
    }

    /**
     * @return array<string,string>
     */
    public function read(): array
    {
        $data = [];
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read .env file');
        }

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $data[trim($name)] = trim($value);
        }

        return $data;
    }

    /**
     * @param array<string,string> $values
     */
    public function update(array $values): void
    {
        $lines = file($this->envPath, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read .env file for updating');
        }

        $remaining = $values;
        foreach ($lines as $index => $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$name] = explode('=', $line, 2);
            $name = trim($name);
            if (array_key_exists($name, $remaining)) {
                $lines[$index] = $name . '=' . $remaining[$name];
                unset($remaining[$name]);
            }
        }

        foreach ($remaining as $key => $value) {
            $lines[] = $key . '=' . $value;
        }

        $content = implode(PHP_EOL, $lines);
        if (!str_ends_with($content, PHP_EOL)) {
            $content .= PHP_EOL;
        }

        if (file_put_contents($this->envPath, $content) === false) {
            throw new \RuntimeException('Unable to write .env file');
        }

        Config::refresh();
    }
}
