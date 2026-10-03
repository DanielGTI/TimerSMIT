<?php

namespace App\Http\Controllers;

use App\Models\OvertimeRequest;
use App\Services\OvertimeCoverageService;
use App\Services\OvertimeRequestService;
use App\Services\TimeEntryService;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Fase 4: "Informar hora extra" (pessoa), conferência do lançamento antes de
 * salvar (aviso e hora a confirmar) e a decisão do aprovador.
 */
class OvertimeController extends Controller
{
    public function __construct(
        private readonly OvertimeRequestService $requests,
        private readonly OvertimeCoverageService $coverage,
    ) {}

    public function mine(TenantContext $tenantContext): JsonResponse
    {
        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        return response()->json([
            'applies' => $this->coverage->applies($tenant, $member),
            'profile' => $member->overtimeProfile(),
            'items' => $this->requests->mine($tenant, $member),
        ]);
    }

    public function inform(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'dateFrom' => ['required', 'date_format:Y-m-d'],
            'dateTo' => ['nullable', 'date_format:Y-m-d'],
            'secondsPerDay' => ['required', 'integer', 'min:60', 'max:86400'],
            'startTime' => ['nullable', 'date_format:H:i'],
            'endTime' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:1000'],
            'suggestedDestination' => ['nullable', Rule::in(OvertimeRequest::DESTINATIONS)],
        ]);

        $created = $this->requests->inform($tenantContext->tenant(), $tenantContext->member(), $data);

        return response()->json($this->requests->present($created), 201);
    }

    public function cancel(TenantContext $tenantContext, int $requestId): JsonResponse
    {
        $cancelled = $this->requests->cancel($tenantContext->tenant(), $tenantContext->member(), $requestId);

        return response()->json($this->requests->present($cancelled));
    }

    /**
     * O que acontece se este lançamento for salvo: hora adicional coberta ou
     * não e, no perfil restrito, o trecho que vira hora extra a confirmar.
     */
    public function check(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'startTime' => ['nullable', 'date_format:H:i'],
            'durationSeconds' => ['required', 'integer', 'min:1', 'max:86400'],
            'entryId' => ['nullable', 'integer'],
        ]);

        $tenant = $tenantContext->tenant();
        $plan = $this->coverage->plan(
            $tenant,
            $tenantContext->member(),
            $data['date'],
            $data['startTime'] ?? null,
            (int) $data['durationSeconds'],
            $tenant->default_timezone ?: 'UTC',
            isset($data['entryId']) ? (int) $data['entryId'] : null,
        );

        $times = fn (array $piece) => [
            'startTime' => $piece['start'] === null ? null : TimeEntryService::clock($piece['start']),
            'endTime' => $piece['end'] === null ? null : TimeEntryService::clock($piece['end']),
            'seconds' => $piece['seconds'],
        ];

        return response()->json([
            'applies' => $plan['applies'],
            'profile' => $plan['profile'],
            'additionalSeconds' => $plan['additionalSeconds'],
            'coveredSeconds' => $plan['coveredSeconds'],
            'uncoveredSeconds' => $plan['uncoveredSeconds'],
            'entries' => array_map($times, $plan['normal']),
            'pending' => array_map($times, $plan['pending']),
        ]);
    }

    public function pending(TenantContext $tenantContext): JsonResponse
    {
        return response()->json($this->requests->pendingFor($tenantContext->tenant(), $tenantContext->member()));
    }

    public function decide(Request $request, TenantContext $tenantContext, int $requestId): JsonResponse
    {
        $data = $request->validate([
            'approve' => ['required', 'boolean'],
            'approvedSecondsPerDay' => ['nullable', 'integer', 'min:60', 'max:86400'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $decided = $this->requests->decide(
            $tenantContext->tenant(),
            $tenantContext->member(),
            $requestId,
            (bool) $data['approve'],
            isset($data['approvedSecondsPerDay']) ? (int) $data['approvedSecondsPerDay'] : null,
            $data['note'] ?? null,
        );

        return response()->json($this->requests->present($decided));
    }
}
