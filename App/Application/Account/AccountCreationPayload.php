<?php

declare(strict_types=1);

namespace App\Application\Account;

use App\Application\Account\Exception\AccountValidationException;

final class AccountCreationPayload
{
    public function __construct(
        private readonly string $brokerName,
        private readonly string $currency,
        private readonly bool $isPrimary
    ) {
    }

    public static function fromArray(array $payload): self
    {
        $brokerName = trim($payload['broker_name'] ?? '');
        $currency = strtoupper(trim($payload['currency'] ?? ''));
        $isPrimary = isset($payload['is_primary'])
            ? filter_var($payload['is_primary'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)
            : false;

        if ($brokerName === '' || $currency === '') {
            throw new AccountValidationException('broker_name and currency are required.');
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
