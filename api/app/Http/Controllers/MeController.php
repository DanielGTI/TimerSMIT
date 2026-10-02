<?php

namespace App\Http\Controllers;

use App\Services\OrganizationSettingsService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint mínimo para provar, de ponta a ponta, que uma sessão emitida por
 * SessionController é aceita pela middleware `tenant` (T006/T009).
 */
class MeController extends Controller
{
    public function __construct(private readonly OrganizationSettingsService $settings) {}

    public function show(TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'tenantId' => (string) $tenantContext->tenant()->id,
            'organizationName' => $tenantContext->tenant()->devops_organization_name,
            'memberId' => (string) $tenantContext->member()->id,
            'displayName' => $tenantContext->member()->display_name,
            // Regras de lançamento em vigor (incremento, limite diário, janela retroativa, comentário).
            'policy' => $this->settings->currentPolicyFor($tenantContext->tenant()),
        ]);
    }
}
