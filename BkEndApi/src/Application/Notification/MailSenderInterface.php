<?php
declare(strict_types=1);

namespace FinHub\Application\Notification;

interface MailSenderInterface
{
    public function send(string $to, string $subject, string $htmlContent, ?string $fromEmail = null, ?string $fromName = null): MailResult;
}
