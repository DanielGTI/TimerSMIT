<?php

namespace App\Services;

use Illuminate\Support\Carbon;

final class SessionToken
{
    public function __construct(
        public readonly string $token,
        public readonly Carbon $expiresAt,
        public readonly int $tenantId,
        public readonly int $memberId,
    ) {}
}
