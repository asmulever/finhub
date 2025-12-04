<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Exception\AccountValidationException;
use App\Domain\Account as DomainAccount;

final class AccountUpdatePayload
{
    public function __construct(
        private readonly string $brokerName,
        private readonly string $currency,
        private readonly bool $isPrimary
    ) {
    }

    public static function fromArray(array $payload, DomainAccount $existing): self
    {
        $brokerName = trim($payload['broker_name'] ?? $existing->getBrokerName());
        $currency = strtoupper(trim($payload['currency'] ?? $existing->getCurrency()));
        $isPrimary = array_key_exists('is_primary', $payload)
            ? filter_var($payload['is_primary'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : $existing->isPrimary();

        if ($brokerName === '' || $currency === '') {
            throw new AccountValidationException('broker_name and currency cannot be empty.');
        }

        return new self(
            $brokerName,
            $currency,
            (bool)$isPrimary
        );
    }

    public function getBrokerName(): string
    {
        return $this->brokerName;
    }

    public function getCurrency(): string
    {
        return $this->currency;
    }

    public function isPrimary(): bool
    {
        return $this->isPrimary;
    }
}
