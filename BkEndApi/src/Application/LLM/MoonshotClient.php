<?php
declare(strict_types=1);

namespace FinHub\Application\LLM;

use FinHub\Infrastructure\Logging\LoggerInterface;

final class MoonshotClient
{
    private string $apiKey;
    private string $baseUrl;
    private LoggerInterface $logger;

    public function __construct(string $apiKey, LoggerInterface $logger, string $baseUrl = 'https://api.moonshot.ai/v1')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->logger = $logger;
    }

    /**
     * @return array{data?:array<mixed>,model?:string,raw?:array<string,mixed>}
     */
    public function listModels(): array
    {
        return $this->request('GET', '/models');
    }

    /**
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $options
     */
    public function chat(array $messages, string $model, array $options = []): array
    {
        $payload = array_merge(
            [
                'model' => $model,
                'messages' => $messages,
            ],
            $options
        );
        return $this->request('POST', '/chat/completions', $payload);
    }

    /**
     * @param array<string,mixed>|null $body
     * @return array<string,mixed>
     */
    private function request(string $method, string $path, ?array $body = null): array
    {
        $url = $this->baseUrl . $path;
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
        ];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            $error = curl_error($ch);
            curl_close($ch);
            $this->logger->error('moonshot.http_error', ['message' => $error]);
            throw new \RuntimeException('No se pudo contactar a Moonshot: ' . $error, 502);
        }
        curl_close($ch);
        $decoded = json_decode($response, true);
        if (!is_array($decoded)) {
            $this->logger->error('moonshot.decode_error', ['body' => $response, 'code' => $httpCode]);
            throw new \RuntimeException('Respuesta Moonshot inválida', 502);
        }
        if ($httpCode >= 400) {
            $this->logger->error('moonshot.api_error', ['code' => $httpCode, 'payload' => $decoded]);
            $message = (string) ($decoded['error']['message'] ?? 'Error de Moonshot');
            throw new \RuntimeException($message, $httpCode);
        }
        return $decoded;
    }
}

