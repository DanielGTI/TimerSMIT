<?php

namespace App\Http\Middleware;

use App\Models\Member;
use App\Models\Tenant;
use App\Services\InvalidSessionTokenException;
use App\Services\SessionTokenService;
use App\Support\TenantContext;
use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exige e valida a sessão própria do backend em toda rota protegida
 * (FR-001, FR-015). Nunca confia em cabeçalhos de tenant/usuário enviados
 * livremente pelo cliente — a única fonte é o JWT assinado pelo backend,
 * emitido após DevOpsIdentityVerifier confirmar a identidade real.
 */
class ResolveTenantContext
{
    public function __construct(
        private readonly SessionTokenService $sessionTokens,
        private readonly TenantContext $tenantContext,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken();

        if (! $token) {
            throw new AuthenticationException('Sessão ausente.');
        }

        try {
            $claims = $this->sessionTokens->parse($token);
        } catch (InvalidSessionTokenException $exception) {
            throw new AuthenticationException($exception->getMessage(), previous: $exception);
        }

        $tenant = Tenant::query()->find($claims->tenantId);
        $member = Member::query()->find($claims->memberId);

        if (! $tenant || ! $tenant->is_active || ! $member || $member->tenant_id !== $tenant->id) {
            throw new AuthenticationException('Sessão não corresponde a um tenant/membro válido.');
        }

        $this->tenantContext->set($tenant, $member);
        Auth::setUser($member);

        return $next($request);
    }
}
