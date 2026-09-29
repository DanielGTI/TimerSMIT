<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Tenant;

final class ProvisionedIdentity
{
    public function __construct(
        public readonly Tenant $tenant,
        public readonly Member $member,
    ) {}
}
