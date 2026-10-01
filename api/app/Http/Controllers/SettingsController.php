<?php

namespace App\Http\Controllers;

use App\Models\RoleAssignment;
use App\Services\OrganizationSettingsService;
use App\Support\TenantContext;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuração da organização (US5, T036). Toda rota exige administrador
 * (middleware `admin`). Cada alteração devolve a visão completa já atualizada,
 * para a tela ter uma única fonte de verdade.
 */
class SettingsController extends Controller
{
    private const ROLES = [
        RoleAssignment::ROLE_MEMBER,
        RoleAssignment::ROLE_APPROVER,
        RoleAssignment::ROLE_MANAGER,
        RoleAssignment::ROLE_ADMIN,
    ];

    public function __construct(
        private readonly OrganizationSettingsService $settings,
        private readonly TenantContext $tenantContext,
    ) {}

    public function show(): JsonResponse
    {
        return $this->overview();
    }

    public function updateOrganization(Request $request): JsonResponse
    {
        $data = $request->validate([
            'timezone' => ['required', 'string', Rule::in(DateTimeZone::listIdentifiers())],
        ]);

        $this->settings->updateTimezone($this->tenantContext->tenant(), $this->tenantContext->member(), $data['timezone']);

        return $this->overview();
    }

    public function updatePolicy(Request $request): JsonResponse
    {
        $data = $request->validate([
            'durationIncrementMinutes' => ['required', 'integer', Rule::in([1, 5, 10, 15, 30, 60])],
            'dailyLimitHours' => ['required', 'integer', 'between:1,24'],
            'retroactiveWindowDays' => ['required', 'integer', 'between:0,365'],
            'commentRequired' => ['required', 'boolean'],
        ]);

        $this->settings->updatePolicy($this->tenantContext->tenant(), $this->tenantContext->member(), $data);

        return $this->overview();
    }

    public function updateProject(Request $request, int $projectId): JsonResponse
    {
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        $this->settings->setProjectEnabled($this->tenantContext->tenant(), $this->tenantContext->member(), $projectId, $data['enabled']);

        return $this->overview();
    }

    public function storeActivityType(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'defaultBillable' => ['nullable', 'boolean'],
        ]);

        $this->settings->createActivityType($this->tenantContext->tenant(), $this->tenantContext->member(), $data);

        return $this->overview();
    }

    public function updateActivityType(Request $request, int $typeId): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:100'],
            'color' => ['sometimes', 'nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'enabled' => ['sometimes', 'boolean'],
            'defaultBillable' => ['sometimes', 'boolean'],
        ]);

        $this->settings->updateActivityType($this->tenantContext->tenant(), $this->tenantContext->member(), $typeId, $data);

        return $this->overview();
    }

    public function grantRole(Request $request): JsonResponse
    {
        $data = $request->validate([
            'memberId' => ['required', 'integer', 'min:1'],
            'role' => ['required', Rule::in(self::ROLES)],
            'projectId' => ['nullable', 'integer', 'min:1'],
        ]);

        $this->settings->grantRole(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            (int) $data['memberId'],
            $data['role'],
            isset($data['projectId']) ? (int) $data['projectId'] : null,
        );

        return $this->overview();
    }

    public function revokeRole(int $assignmentId): JsonResponse
    {
        $this->settings->revokeRole($this->tenantContext->tenant(), $this->tenantContext->member(), $assignmentId);

        return $this->overview();
    }

    public function designateApprover(Request $request): JsonResponse
    {
        $data = $request->validate([
            'memberId' => ['required', 'integer', 'min:1'],
            'approverId' => ['required', 'integer', 'min:1'],
            'projectId' => ['nullable', 'integer', 'min:1'],
            'applyToPending' => ['nullable', 'boolean'],
        ]);

        $this->settings->designateApprover(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            (int) $data['memberId'],
            (int) $data['approverId'],
            isset($data['projectId']) ? (int) $data['projectId'] : null,
            (bool) ($data['applyToPending'] ?? false),
        );

        return $this->overview();
    }

    public function syncPeople(Request $request): JsonResponse
    {
        $data = $request->validate([
            // Lista vazia é recusada: viria de uma leitura que falhou e
            // marcaria todo mundo como inativo.
            'people' => ['required', 'array', 'min:1', 'max:5000'],
            'people.*.identityId' => ['required', 'uuid'],
            'people.*.displayName' => ['required', 'string', 'max:200'],
        ]);

        $this->settings->syncDirectory($this->tenantContext->tenant(), $this->tenantContext->member(), $data['people']);

        return $this->overview();
    }

    public function removeDesignation(Request $request, int $assignmentId): JsonResponse
    {
        $data = $request->validate(['applyToPending' => ['nullable', 'boolean']]);

        $this->settings->removeDesignation(
            $this->tenantContext->tenant(),
            $this->tenantContext->member(),
            $assignmentId,
            (bool) ($data['applyToPending'] ?? false),
        );

        return $this->overview();
    }

    private function overview(): JsonResponse
    {
        return response()->json($this->settings->overview($this->tenantContext->tenant()));
    }
}
