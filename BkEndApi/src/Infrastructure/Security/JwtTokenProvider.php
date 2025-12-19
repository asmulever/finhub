<?php
namespace FinHub\Infrastructure\Security;

final class JwtTokenProvider
{
    private string $secret;
    private string $algo;

    public function __construct(string $secret, string $algo = 'HS256')
    {
        $this->secret = $secret;
        $this->algo = $algo;
    }

    public function issue(array $payload, int $ttlSeconds): string
    {
        $header = ['alg' => $this->algo, 'typ' => 'JWT'];
        $iat = time();
        $exp = $iat + $ttlSeconds;
        $payload['iat'] = $iat;
        $payload['exp'] = $exp;
        $segments = [
            $this->base64urlEncode(json_encode($header)),
            $this->base64urlEncode(json_encode($payload)),
        ];
        $signature = $this->sign(implode('.', $segments));
        $segments[] = $signature;
        return implode('.', $segments);
    }

    /** @throws \InvalidArgumentException */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \InvalidArgumentException('Token inválido');
        }
        [$header, $payload, $signature] = $parts;
        $decodedPayload = json_decode($this->base64urlDecode($payload), true);
        if (!is_array($decodedPayload)) {
            throw new \InvalidArgumentException('Token inválido');
        }
        if ($this->sign($header . '.' . $payload) !== $signature) {
            throw new \InvalidArgumentException('Token inválido');
        }
        if (isset($decodedPayload['exp']) && time() > (int) $decodedPayload['exp']) {
            throw new \InvalidArgumentException('Token expirado');
        }
        return $decodedPayload;
    }

    private function sign(string $message): string
    {
        $hash = hash_hmac('sha256', $message, $this->secret, true);
        return $this->base64urlEncode($hash);
    }

    private function base64urlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64urlDecode(string $value): string
    {
        $padding = 4 - (strlen($value) % 4);
        if ($padding < 4) {
            $value .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
