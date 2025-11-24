<?php

declare(strict_types=1);

namespace App\Interfaces\Security;

interface AuthorizationHeaderProvider
{
    public function getAuthorizationHeader(): ?string;
}
