<?php

namespace App\Http\Controllers;

use App\Services\ActivityTypeService;
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
            'activityTypeId' => ['nullable', 'integer', ActivityTypeService::validIdRule($tenant)],
            'billable' => ['nullable', 'boolean'],
            'note' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string'],
            'workItemType' => ['nullable', 'string'],
            'iterationPath' => ['nullable', 'string', 'max:500'],
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

        $entry = $this->entries->createManual(
            tenant: $tenant,
            member: $member,
            project: $project,
            devopsWorkItemId: $data['workItemId'],
            localDate: $data['localDate'],
            durationSeconds: $data['durationSeconds'],
            activityTypeId: $data['activityTypeId'] ?? null,
            billable: $data['billable'] ?? null,
            note: $data['note'] ?? null,
        );

        return response()->json(TimeEntryPresenter::present($entry), 201);
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
