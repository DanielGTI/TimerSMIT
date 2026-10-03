<?php

namespace App\Http\Controllers;

use App\Models\Member;
use App\Models\Project;
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
            // Projetos (id do Azure DevOps) que cobram por hora: só neles o lançamento mostra "faturável".
            'billableProjectIds' => Project::query()
                ->where('tenant_id', $tenantContext->tenant()->id)
                ->where('uses_billable', true)
                ->pluck('devops_project_id')
                ->values()
                ->all(),
            // O formulário de lançamento exige De/Até quando isto vem verdadeiro.
            'overtime' => $this->overtimeFor($tenantContext),
        ]);
    }

    /**
     * @return array{enabled: bool, requireTimeOfDay: bool, workdayStart: string, workdayEnd: string}
     */
    private function overtimeFor(TenantContext $tenantContext): array
    {
        $rules = $this->overtime->present($this->overtime->current($tenantContext->tenant()));

        return [
            'enabled' => $rules['enabled'],
            'requireTimeOfDay' => $this->overtime->requiresTimeOfDay($tenantContext->tenant(), $tenantContext->member()),
            'workdayStart' => $rules['workdayStart'],
            'workdayEnd' => $rules['workdayEnd'],
        ];
    }
}
