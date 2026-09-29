<?php

namespace App\Services;

final class SessionTokenClaims
{
    public function __construct(
        public readonly int $tenantId,
        public readonly int $memberId,
    ) {}
}
