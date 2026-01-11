<?php
declare(strict_types=1);

namespace FinHub\Infrastructure\Mail;

use FinHub\Application\Notification\MailResult;
use FinHub\Application\Notification\MailSenderInterface;
use FinHub\Infrastructure\Config\Config;

/**
 * Envío de correos transaccionales via Brevo HTTP API.
 */
final class BrevoMailSender implements MailSenderInterface
{
    private Config $config;

    public function __construct(Config $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $subject, string $htmlContent, ?string $fromEmail = null, ?string $fromName = null): MailResult
    {
        $apiKey = trim((string) $this->config->get('BREVO_API_V3_KEY', ''));
        if ($apiKey === '') {
            throw new \RuntimeException('BREVO_API_V3_KEY no configurada', 500);
        }

        $defaultSender = (string) $this->config->get('brevo_sender_email', $this->config->get('BREVO_SENDER_EMAIL', ''));
        $senderEmail = $fromEmail !== null && $fromEmail !== '' ? $fromEmail : $defaultSender;
        if ($senderEmail === '') {
            throw new \RuntimeException('brevo_sender_email no configurado', 500);
        }
        $senderName = $fromName !== null && $fromName !== '' ? $fromName : (string) $this->config->get('BREVO_SENDER_NAME', 'FinHub');

        $payload = [
            'sender' => ['email' => $senderEmail, 'name' => $senderName],
            'to' => [['email' => $to]],
            'subject' => $subject,
            'htmlContent' => $htmlContent,
            // Desactivar tracking de aperturas/clicks para evitar reescritura de enlaces.
            'trackOpens' => false,
            'trackClicks' => false,
            'headers' => [
                'X-Mailin-Track' => '0',
                'X-Mailin-TrackOpens' => '0',
                'X-Mailin-TrackClicks' => '0',
            ],
        ];

        $jsonPayload = json_encode($payload);
        if ($jsonPayload === false) {
            throw new \RuntimeException('No se pudo serializar el payload de Brevo', 500);
        }

        $curl = curl_init('https://api.brevo.com/v3/smtp/email');
        if ($curl === false) {
            throw new \RuntimeException('No se pudo inicializar cURL para Brevo', 500);
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => (int) $this->config->get('BREVO_TIMEOUT_SECONDS', 15),
        ]);

        $responseBody = curl_exec($curl);
        if ($responseBody === false) {
            $error = curl_error($curl);
            curl_close($curl);
            throw new \RuntimeException('cURL error Brevo: ' . $error, 500);
        }

        $statusCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        return new MailResult($statusCode, (string) $responseBody);
    }
}
