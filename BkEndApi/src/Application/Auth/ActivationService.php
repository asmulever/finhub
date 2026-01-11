<?php
declare(strict_types=1);

namespace FinHub\Application\Auth;

use FinHub\Application\Notification\MailResult;
use FinHub\Application\Notification\MailSenderInterface;
use FinHub\Domain\User\User;
use FinHub\Domain\User\UserRepositoryInterface;
use FinHub\Infrastructure\Config\Config;
use FinHub\Infrastructure\Security\JwtTokenProvider;
use FinHub\Infrastructure\Security\PasswordHasher;

/**
 * Caso de uso: registro y envío de correo de activación de usuario.
 */
final class ActivationService
{
    private UserRepositoryInterface $userRepository;
    private PasswordHasher $passwordHasher;
    private JwtTokenProvider $tokenProvider;
    private Config $config;
    private MailSenderInterface $mailSender;

    public function __construct(
        UserRepositoryInterface $userRepository,
        PasswordHasher $passwordHasher,
        JwtTokenProvider $tokenProvider,
        Config $config,
        MailSenderInterface $mailSender
    ) {
        $this->userRepository = $userRepository;
        $this->passwordHasher = $passwordHasher;
        $this->tokenProvider = $tokenProvider;
        $this->config = $config;
        $this->mailSender = $mailSender;
    }

    /**
     * Registra usuario deshabilitado y envía correo de activación.
     *
     * @return array{user: User, mail_result: MailResult, activation_url: string}
     */
    public function registerAndSendActivation(string $email, string $password): array
    {
        $this->assertValidCredentials($email, $password);

        $existing = $this->userRepository->findByEmail($email);
        if ($existing !== null && $existing->isActive()) {
            throw new \RuntimeException('Email ya registrado', 409);
        }

        $hash = $this->passwordHasher->hash($password);
        if ($existing !== null) {
            $user = $this->userRepository->update($existing->getId(), [
                'status' => 'disabled',
                'password_hash' => $hash,
            ]);
            if ($user === null) {
                throw new \RuntimeException('No se pudo preparar la reactivación', 500);
            }
        } else {
            $user = $this->userRepository->create($email, 'user', 'disabled', $hash);
        }

        $tokenTtl = (int) $this->config->get('ACTIVATION_TTL_SECONDS', 1800);
        if ($tokenTtl < 60) {
            $tokenTtl = 1800;
        }
        $tokenPayload = [
            'sub' => $user->getId(),
            'email' => $user->getEmail(),
            'type' => 'activation',
        ];
        $token = $this->tokenProvider->issue($tokenPayload, $tokenTtl);
        $activationUrl = $this->buildActivationUrl($token);

        $subject = 'Validacion Usuario FinHub';
        $html = $this->buildActivationHtml($user->getEmail(), $activationUrl, $tokenTtl);
        $mailResult = $this->mailSender->send($user->getEmail(), $subject, $html);

        return [
            'user' => $user,
            'mail_result' => $mailResult,
            'activation_url' => $activationUrl,
        ];
    }

    private function assertValidCredentials(string $email, string $password): void
    {
        if ($email === '' || $password === '') {
            throw new \RuntimeException('Email y contraseña requeridos', 422);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \RuntimeException('Email inválido', 422);
        }
        if (strlen($password) < 6) {
            throw new \RuntimeException('La contraseña debe tener al menos 6 caracteres', 422);
        }
    }

    private function buildActivationUrl(string $token): string
    {
        $apiBase = '/' . trim((string) $this->config->get('API_BASE_PATH', '/api'), '/');
        $baseUrl = rtrim((string) $this->config->get('APP_BASE_URL', ''), '/');
        $tokenPath = '/auth/activate?token=' . urlencode($token);

        if ($baseUrl === '') {
            return $apiBase . $tokenPath;
        }

        // Si APP_BASE_URL ya incluye el API_BASE_PATH (ej. https://localhost:8080/api), evitar duplicarlo.
        if (str_ends_with($baseUrl, $apiBase)) {
            return $baseUrl . $tokenPath;
        }

        return $baseUrl . $apiBase . $tokenPath;
    }

    private function buildActivationHtml(string $email, string $activationUrl, int $ttlSeconds): string
    {
        $minutes = (int) floor($ttlSeconds / 60);
        $sender = (string) $this->config->get('brevo_sender_email', $this->config->get('BREVO_SENDER_EMAIL', ''));

        return <<<HTML
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0" />
  <title>Validacion Usuario FinHub</title>
  <style>
    body { font-family: 'Segoe UI', Arial, sans-serif; background: #0b1224; color: #e2e8f0; padding: 0; margin: 0; }
    .wrapper { max-width: 640px; margin: 0 auto; padding: 32px 20px; }
    .card { background: linear-gradient(135deg, rgba(10, 17, 35, 0.96), rgba(5, 10, 24, 0.92)); border: 1px solid rgba(56, 189, 248, 0.2); border-radius: 18px; padding: 28px; box-shadow: 0 15px 40px rgba(2, 6, 23, 0.5); }
    h1 { margin: 0 0 12px; font-size: 24px; color: #cbd5f5; }
    p { margin: 0 0 12px; line-height: 1.6; color: #cbd5e1; }
    .muted { color: #94a3b8; font-size: 14px; }
    .button { display: inline-block; padding: 14px 22px; background: linear-gradient(120deg, #38bdf8, #2563eb); color: #0b1224; border-radius: 12px; text-decoration: none; font-weight: 700; letter-spacing: 0.02em; box-shadow: 0 10px 25px rgba(37, 99, 235, 0.35); }
    .button:hover { transform: translateY(-1px); box-shadow: 0 12px 28px rgba(56, 189, 248, 0.35); }
    .footer { margin-top: 18px; font-size: 12px; color: #64748b; }
    .pill { display: inline-block; padding: 6px 10px; border-radius: 999px; background: rgba(56, 189, 248, 0.1); color: #7dd3fc; font-size: 12px; margin-bottom: 12px; letter-spacing: 0.05em; }
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="pill">Activación FinHub</div>
      <h1>Activa tu cuenta</h1>
      <p>Hola, <strong>{$email}</strong> 👋</p>
      <p>Gracias por registrarte en FinHub. Para proteger tu acceso necesitamos validar tu correo. Haz clic en el botón para activar tu cuenta.</p>
      <p style="margin: 20px 0;">
        <a href="{$activationUrl}" target="_blank" rel="noopener" style="color:#38bdf8;font-weight:700;text-decoration:underline;">Activar cuenta</a>
      </p>
      <p class="muted">El enlace vence en aproximadamente {$minutes} minutos. Si no solicitaste esta cuenta, ignora este correo.</p>
      <p class="footer">Enviado desde FinHub • Remitente: {$sender}</p>
    </div>
  </div>
</body>
</html>
HTML;
    }
}
