<?php

namespace App\Support;

use App\Models\Member;
use App\Models\Tenant;
use RuntimeException;

/**
 * Ligado ao container por requisição pela middleware ResolveTenantContext.
 * Qualquer serviço que precise saber "de quem"/"de qual organização" é a
 * chamada atual deve depender disto em vez de ler o token/JWT diretamente
 * (FR-001, FR-015).
 */
class TenantContext
{
    private ?Tenant $tenant = null;

    private ?Member $member = null;

    public function set(Tenant $tenant, Member $member): void
    {
        $this->tenant = $tenant;
        $this->member = $member;
    }

    public function tenant(): Tenant
    {
        return $this->tenant ?? throw new RuntimeException('Tenant não resolvido para esta requisição.');
    }

    public function member(): Member
    {
        return $this->member ?? throw new RuntimeException('Membro não resolvido para esta requisição.');
    }

    public function isResolved(): bool
    {
        return $this->tenant !== null && $this->member !== null;
    }
}
