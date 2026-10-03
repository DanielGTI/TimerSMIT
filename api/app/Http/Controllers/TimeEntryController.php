<?php

namespace App\Http\Controllers;

use App\Services\ActivityTypeService;
use App\Services\OvertimeRequestService;
use App\Services\TimeEntryService;
use App\Services\WorkItemAccessService;
use App\Support\TenantContext;
use App\Support\TimeEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lançamentos manuais (US1, T018) conforme contracts/openapi.yaml (`/entries`).
 */
class TimeEntryController extends Controller
{
    public function __construct(
        private readonly TimeEntryService $entries,
        private readonly WorkItemAccessService $access,
        private readonly OvertimeRequestService $overtimeRequests,
    ) {}

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->requireIdempotencyKey($request);

        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        $data = $request->validate([
            'projectId' => ['required', 'string'],
            'projectName' => ['required', 'string'],
            'workItemId' => ['required', 'integer', 'min:1'],
            'localDate' => ['required', 'date_format:Y-m-d'],
            'durationSeconds' => ['required', 'integer', 'min:1'],
            // Horário de início opcional (hora local, HH:MM); o fim é início + duração.
            'startTime' => ['nullable', 'date_format:H:i'],
            'activityTypeId' => ['nullable', 'integer', ActivityTypeService::validIdRule($tenant)],
            'billable' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string'],
            'workItemType' => ['nullable', 'string'],
            'iterationPath' => ['nullable', 'string', 'max:500'],
            // Perfil restrito: motivo e ciência da hora extra a confirmar.
            'overtimeReason' => ['nullable', 'string', 'max:500'],
            'overtimeAcknowledged' => ['nullable', 'boolean'],
        ]);

        $project = $this->access->authorize(
            tenant: $tenant,
            member: $member,
            devopsProjectId: $data['projectId'],
            devopsProjectName: $data['projectName'],
            devopsWorkItemId: $data['workItemId'],
            title: $data['title'] ?? null,
            workItemType: $data['workItemType'] ?? null,
            iterationPath: $data['iterationPath'] ?? null,
        );

        $result = $this->entries->createManual(
            tenant: $tenant,
            member: $member,
            project: $project,
            devopsWorkItemId: $data['workItemId'],
            localDate: $data['localDate'],
            durationSeconds: $data['durationSeconds'],
            activityTypeId: $data['activityTypeId'] ?? null,
            billable: $data['billable'] ?? null,
            note: $data['note'] ?? null,
            startTime: $data['startTime'] ?? null,
            overtimeReason: $data['overtimeReason'] ?? null,
            overtimeAcknowledged: (bool) ($data['overtimeAcknowledged'] ?? false),
        );

        $first = $result['entries'][0] ?? null;
        $body = $first ? TimeEntryPresenter::present($first) : ['id' => null];

        // Perfil restrito com trecho fora do expediente: o que entrou e o que ficou a confirmar.
        if ($result['pending'] !== []) {
            $body['entries'] = array_map(fn ($entry) => TimeEntryPresenter::present($entry), $result['entries']);
            $body['pendingOvertime'] = array_map(fn ($request) => $this->overtimeRequests->present($request), $result['pending']);
        }

        return response()->json($body, 201);
    }

    public function update(Request $request, TenantContext $tenantContext, int $entryId): JsonResponse
    {
        $expectedRevision = (int) $request->header('If-Match');

        if ($expectedRevision < 1) {
            throw ValidationException::withMessages([
                'If-Match' => 'Cabeçalho If-Match ausente ou inválido.',
            ]);
        }

        $changes = $request->validate([
            'durationSeconds' => ['sometimes', 'integer', 'min:1'],
            // null apaga o horário do lançamento.
            'startTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'billable' => ['sometimes', 'boolean'],
        ]);

        $entry = $this->entries->update(
            tenant: $tenantContext->tenant(),
            member: $tenantContext->member(),
            entryId: $entryId,
            expectedRevision: $expectedRevision,
            changes: $changes,
        );

        return response()->json(TimeEntryPresenter::present($entry));
    }

    public function destroy(TenantContext $tenantContext, int $entryId): Response
    {
        $this->entries->delete($tenantContext->tenant(), $tenantContext->member(), $entryId);

        return response()->noContent();
    }

    private function requireIdempotencyKey(Request $request): string
    {
        $key = $request->header('Idempotency-Key');

        if (! is_string($key) || strlen($key) < 16 || strlen($key) > 128) {
            throw ValidationException::withMessages([
                'Idempotency-Key' => 'Cabeçalho Idempotency-Key ausente ou fora do tamanho esperado (16-128).',
            ]);
        }

        return $key;
    }
}
