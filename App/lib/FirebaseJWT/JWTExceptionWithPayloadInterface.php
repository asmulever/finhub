<?php

namespace Firebase\JWT;

interface JWTExceptionWithPayloadInterface
{
    public function setPayload(object $payload): void;

    public function getPayload(): ?object;
}
