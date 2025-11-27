<?php

declare(strict_types=1);

namespace App\Interfaces;

use App\Infrastructure\EnvManager;
use App\Infrastructure\JwtService;

class SettingsController extends BaseController
{
    public function __construct(
        private readonly EnvManager $envManager,
        private readonly JwtService $jwtService
    ) {
    }

    public function showFinnhub(): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        $data = $this->envManager->read();

        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'settings' => [
                'USR_KEY' => $data['USR_KEY'] ?? '',
                'FINNHUB_API_KEY' => $data['FINNHUB_API_KEY'] ?? '',
                'X_FINNHUB_SECRET' => $data['X_FINNHUB_SECRET'] ?? '',
                'CRON_ACTIVO' => (int)($data['CRON_ACTIVO'] ?? 0),
                'CRON_INTERVALO' => (int)($data['CRON_INTERVALO'] ?? 60),
                'CRON_HR_START' => $data['CRON_HR_START'] ?? '09:00',
                'CRON_HR_END' => $data['CRON_HR_END'] ?? '18:00',
            ],
        ]);
    }

    public function updateFinnhub(): void
    {
        if ($this->authorizeAdmin($this->jwtService) === null) {
            return;
        }

        $input = $this->getJsonInput();
        if ($input === null) {
            return;
        }

        $interval = max(60, (int)($input['CRON_INTERVALO'] ?? 60));
        $cronActive = (int)($input['CRON_ACTIVO'] ?? 0);
        $start = $this->sanitizeTime($input['CRON_HR_START'] ?? '09:00');
        $end = $this->sanitizeTime($input['CRON_HR_END'] ?? '18:00');

        if ($start === null || $end === null) {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'Formato de hora inválido (usar HH:MM)']);
            return;
        }

        $apiKey = trim((string)($input['FINNHUB_API_KEY'] ?? ''));
        if ($apiKey === '') {
            http_response_code(422);
            echo json_encode(['ok' => false, 'error' => 'La API Key es obligatoria']);
            return;
        }

        $pairs = [
            'USR_KEY' => trim((string)($input['USR_KEY'] ?? '')),
            'FINNHUB_API_KEY' => $apiKey,
            'X_FINNHUB_SECRET' => trim((string)($input['X_FINNHUB_SECRET'] ?? '')),
            'CRON_ACTIVO' => (string)$cronActive,
            'CRON_INTERVALO' => (string)$interval,
            'CRON_HR_START' => $start,
            'CRON_HR_END' => $end,
        ];

        $this->envManager->update($pairs);

        http_response_code(200);
        echo json_encode(['ok' => true, 'message' => 'Datos guardados']);
    }

    private function sanitizeTime(string $value): ?string
    {
        $trimmed = trim($value);
        return preg_match('/^\d{2}:\d{2}$/', $trimmed) ? $trimmed : null;
    }
}
