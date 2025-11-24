<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

class CompositeAuthorizationHeaderProvider implements AuthorizationHeaderProvider
{
    /**
     * @param AuthorizationHeaderProvider[] $providers
     */
    public function __construct(private readonly array $providers)
    {
    }

    public function getAuthorizationHeader(): ?string
    {
        foreach ($this->providers as $provider) {
            $header = $provider->getAuthorizationHeader();
            if (is_string($header) && $header !== '') {
                return $header;
            }
        }

        return null;
    }
}
