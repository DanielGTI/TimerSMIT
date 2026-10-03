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
 * Correção de lançamentos de qualquer pessoa pelo administrador, a partir do
 * relatório detalhado. Só em semana aberta ou rejeitada; tudo auditado.
 */
class AdminTimeEntryController extends Controller
{
    public function __construct(
        private readonly TimeEntryService $entries,
        private readonly WorkItemAccessService $access,
    ) {}

    public function update(Request $request, TenantContext $tenantContext, int $entryId): JsonResponse
    {
        $expectedRevision = (int) $request->header('If-Match');

        if ($expectedRevision < 1) {
            throw ValidationException::withMessages([
                'If-Match' => 'Cabeçalho If-Match ausente ou inválido.',
            ]);
        }

        $tenant = $tenantContext->tenant();
        $admin = $tenantContext->member();

        $data = $request->validate([
            'localDate' => ['sometimes', 'date_format:Y-m-d'],
            'startTime' => ['sometimes', 'nullable', 'date_format:H:i'],
            'durationSeconds' => ['sometimes', 'integer', 'min:1', 'max:86400'],
            'activityTypeId' => ['sometimes', 'nullable', 'integer', ActivityTypeService::validIdRule($tenant)],
            'note' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'billable' => ['sometimes', 'boolean'],
            // Outro work item (de qualquer projeto): vem com o projeto dele no Azure DevOps.
            'workItemId' => ['sometimes', 'integer', 'min:1'],
            'projectId' => ['required_with:workItemId', 'string'],
            'projectName' => ['required_with:workItemId', 'string'],
            'title' => ['nullable', 'string'],
            'workItemType' => ['nullable', 'string'],
            'iterationPath' => ['nullable', 'string', 'max:500'],
        ]);

        $project = null;
        if (isset($data['workItemId'])) {
            $project = $this->access->authorize(
                tenant: $tenant,
                member: $admin,
                devopsProjectId: $data['projectId'],
                devopsProjectName: $data['projectName'],
                devopsWorkItemId: $data['workItemId'],
                title: $data['title'] ?? null,
                workItemType: $data['workItemType'] ?? null,
                iterationPath: $data['iterationPath'] ?? null,
            );
        }

        $changes = array_intersect_key($data, array_flip([
            'localDate', 'startTime', 'durationSeconds', 'activityTypeId', 'note', 'billable', 'workItemId',
        ]));
        if (array_key_exists('note', $changes) && $changes['note'] !== null && trim($changes['note']) === '') {
            $changes['note'] = null;
        }

        $entry = $this->entries->adminUpdate($tenant, $admin, $entryId, $expectedRevision, $changes, $project);

        return response()->json(TimeEntryPresenter::present($entry));
    }

    public function destroy(TenantContext $tenantContext, int $entryId): Response
    {
        $this->entries->adminDelete($tenantContext->tenant(), $tenantContext->member(), $entryId);

        return response()->noContent();
    }
}
