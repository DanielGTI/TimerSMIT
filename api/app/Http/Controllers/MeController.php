<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Services\OrganizationSettingsService;
use App\Services\OvertimeRuleService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * Endpoint mínimo para provar, de ponta a ponta, que uma sessão emitida por
 * SessionController é aceita pela middleware `tenant` (T006/T009).
 */
class MeController extends Controller
{
    public function __construct(
        private readonly OrganizationSettingsService $settings,
        private readonly OvertimeRuleService $overtime,
    ) {}

    public function show(TenantContext $tenantContext): JsonResponse
    {
        return response()->json([
            'tenantId' => (string) $tenantContext->tenant()->id,
            'organizationName' => $tenantContext->tenant()->devops_organization_name,
            'memberId' => (string) $tenantContext->member()->id,
            'displayName' => $tenantContext->member()->display_name,
            'hoursRegime' => $tenantContext->member()->hours_regime ?? Member::REGIME_CLT,
            // Regras de lançamento em vigor (incremento, limite diário, janela retroativa, comentário).
            'policy' => $this->settings->currentPolicyFor($tenantContext->tenant()),
            // O formulário de lançamento exige De/Até quando isto vem verdadeiro.
            'overtime' => $this->overtimeFor($tenantContext),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function overtimeFor(TenantContext $tenantContext): array
    {
        $rules = $this->overtime->present($this->overtime->current($tenantContext->tenant()));

        return [
            'enabled' => $rules['enabled'],
            'requireTimeOfDay' => $this->overtime->requiresTimeOfDay($tenantContext->tenant(), $tenantContext->member()),
            'workdayStart' => $rules['workdayStart'],
            'workdayEnd' => $rules['workdayEnd'],
            // Limites que geram aviso na folha (a página de Instruções mostra os valores).
            'alertDailyExtraHours' => $rules['alertDailyExtraHours'],
            'alertWeeklyHours' => $rules['alertWeeklyHours'],
            'alertRestHours' => $rules['alertRestHours'],
        ];
    }
}
