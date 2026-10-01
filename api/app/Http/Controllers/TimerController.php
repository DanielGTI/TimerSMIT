<?php

namespace App\Http\Controllers;

use App\Models\TimerSession;
use App\Services\ActivityTypeService;
use App\Services\TimerService;
use App\Services\WorkItemAccessService;
use App\Support\TenantContext;
use App\Support\TimeEntryPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Timer do próprio membro (US1, T018). Início/fim conforme
 * contracts/openapi.yaml (`/me/timer`, `/me/timer/stop`); autorização de
 * projeto/work item delegada a WorkItemAccessService (T014).
 */
class TimerController extends Controller
{
    public function __construct(
        private readonly TimerService $timers,
        private readonly WorkItemAccessService $access,
    ) {}

    public function show(TenantContext $tenantContext): JsonResponse
    {
        $timer = TimerSession::query()
            ->where('tenant_id', $tenantContext->tenant()->id)
            ->where('member_id', $tenantContext->member()->id)
            ->where('status', TimerSession::STATUS_ACTIVE)
            ->first();

        if (! $timer) {
            // response()->json(null) viraria `{}` (JsonResponse troca null por
            // objeto vazio) — truthy no cliente, que trataria como timer ativo.
            return new JsonResponse('null', 200, [], 0, true);
        }

        return response()->json($this->toArray($timer));
    }

    public function store(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $idempotencyKey = $this->requireIdempotencyKey($request);

        $tenant = $tenantContext->tenant();
        $member = $tenantContext->member();

        $data = $request->validate([
            'projectId' => ['required', 'string'],
            'projectName' => ['required', 'string'],
            'workItemId' => ['required', 'integer', 'min:1'],
            'activityTypeId' => ['nullable', 'integer', ActivityTypeService::validIdRule($tenant)],
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

        $timer = $this->timers->start(
            tenant: $tenant,
            member: $member,
            project: $project,
            devopsWorkItemId: $data['workItemId'],
            activityTypeId: $data['activityTypeId'] ?? null,
            idempotencyKey: $idempotencyKey,
        );

        return response()->json($this->toArray($timer), 201);
    }

    public function stop(Request $request, TenantContext $tenantContext): JsonResponse
    {
        $this->requireIdempotencyKey($request);

        $data = $request->validate([
            'timerId' => ['required', 'integer'],
            'note' => ['nullable', 'string', 'max:2000'],
            'billable' => ['nullable', 'boolean'],
        ]);

        $entries = $this->timers->stop(
            tenant: $tenantContext->tenant(),
            member: $tenantContext->member(),
            timerId: $data['timerId'],
            note: $data['note'] ?? null,
            billable: $data['billable'] ?? null,
        );

        return response()->json($entries->map(fn ($entry) => TimeEntryPresenter::present($entry))->values());
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

    private function toArray(TimerSession $timer): array
    {
        return [
            'id' => (string) $timer->id,
            'workItemId' => $timer->devops_work_item_id,
            'startedAtUtc' => $timer->started_at_utc->toIso8601String(),
            'status' => $timer->status,
            'activityTypeId' => $timer->activity_type_id !== null ? (string) $timer->activity_type_id : null,
        ];
    }
}
