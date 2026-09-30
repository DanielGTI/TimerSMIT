<?php

namespace App\Http\Middleware;

use App\Services\ApproverResolver;
use App\Support\TenantContext;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Configuração da organização é só de administrador (papel `admin` valendo
 * para a organização inteira). Roda depois de `tenant`, que resolve quem é.
 */
class EnsureTenantAdmin
{
    public function __construct(
        private readonly TenantContext $tenantContext,
        private readonly ApproverResolver $approvers,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->approvers->isAdmin($this->tenantContext->member())) {
            throw new AuthorizationException;
        }

        return $next($request);
    }
}
