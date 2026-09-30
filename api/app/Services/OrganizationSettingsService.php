<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\ActivityType;
use App\Models\ApproverAssignment;
use App\Models\Member;
use App\Models\Policy;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Support\WeekCalendar;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Configuração da organização (US5, FR-010), sempre por administrador.
 *
 * Regras de lançamento são VERSIONADAS: cada alteração cria uma nova versão
 * de `policies` valendo a partir de agora. Nada é reescrito nem
 * reclassificado — lançamentos antigos guardam sua data local e fuso, e as
 * regras só se aplicam a operações novas.
 */
class OrganizationSettingsService
{
    /** Padrões usados enquanto a organização não cadastrou política (iguais aos da migração). */
    public const POLICY_DEFAULTS = [
        'durationIncrementMinutes' => 1,
        'dailyLimitHours' => 24,
        'retroactiveWindowDays' => 30,
        'commentRequired' => false,
    ];

    public function __construct(
        private readonly AuditService $audit,
        private readonly ApproverResolver $approvers,
        private readonly ActivityTypeService $activityTypes,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function overview(Tenant $tenant): array
    {
        // A administração precisa ver (e poder editar) o conjunto padrão de
        // atividades mesmo que ninguém tenha aberto a listagem ainda.
        $this->activityTypes->ensureDefaults($tenant);

        $projects = Project::query()->where('tenant_id', $tenant->id)->orderBy('devops_project_name')->get();
        $members = Member::query()->where('tenant_id', $tenant->id)->orderBy('display_name')->get();
        $roles = RoleAssignment::query()->where('tenant_id', $tenant->id)->orderBy('id')->get()->groupBy('member_id');
        $projectNames = $projects->pluck('devops_project_name', 'id');

        return [
            'organization' => ['name' => $tenant->devops_organization_name, 'timezone' => $tenant->default_timezone],
            'policy' => $this->presentPolicy($this->currentPolicy($tenant)),
            'projects' => $projects->map(fn (Project $project) => [
                'id' => (string) $project->id,
                'name' => $project->devops_project_name,
                'enabled' => $project->is_enabled,
            ])->all(),
            'activityTypes' => ActivityType::query()->where('tenant_id', $tenant->id)->orderBy('name')->get()
                ->map(fn (ActivityType $type) => $this->presentActivityType($type))->all(),
            'members' => $members->map(fn (Member $member) => [
                'id' => (string) $member->id,
                'name' => $member->display_name,
                'roles' => ($roles->get($member->id) ?? collect())->map(fn (RoleAssignment $role) => [
                    'id' => (string) $role->id,
                    'role' => $role->role,
                    'projectId' => $role->project_id === null ? null : (string) $role->project_id,
                    'projectName' => $role->project_id === null ? null : $projectNames->get($role->project_id),
                ])->values()->all(),
            ])->all(),
            'designations' => ApproverAssignment::query()->where('tenant_id', $tenant->id)->orderBy('id')->get()
                ->map(fn (ApproverAssignment $assignment) => [
                    'id' => (string) $assignment->id,
                    'memberId' => (string) $assignment->member_id,
                    'memberName' => $members->firstWhere('id', $assignment->member_id)?->display_name,
                    'approverId' => (string) $assignment->approver_id,
                    'approverName' => $members->firstWhere('id', $assignment->approver_id)?->display_name,
                    'projectId' => $assignment->project_id === null ? null : (string) $assignment->project_id,
                    'projectName' => $assignment->project_id === null ? null : $projectNames->get($assignment->project_id),
                ])->all(),
        ];
    }

    // ---------- organização e regras ----------

    public function updateTimezone(Tenant $tenant, Member $actor, string $timezone): void
    {
        $before = $tenant->default_timezone;

        // Só vale para lançamentos novos: cada lançamento guarda o próprio fuso
        // e a data local de quando foi criado.
        $tenant->update(['default_timezone' => $timezone]);

        $this->audit->record($tenant, 'settings.timezone_updated', Tenant::class, $tenant->id, $actor, null, [
            'before' => $before,
            'after' => $timezone,
        ]);
    }

    /**
     * @param  array{durationIncrementMinutes: int, dailyLimitHours: int, retroactiveWindowDays: int, commentRequired: bool}  $values
     */
    public function updatePolicy(Tenant $tenant, Member $actor, array $values): Policy
    {
        return DB::transaction(function () use ($tenant, $actor, $values) {
            // Serializa edições simultâneas para a numeração de versões não colidir.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            $before = $this->presentPolicy($this->currentPolicy($tenant));
            $version = (int) Policy::query()->where('tenant_id', $tenant->id)->whereNull('project_id')->max('version') + 1;

            $policy = Policy::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => null,
                'version' => $version,
                'duration_increment_minutes' => $values['durationIncrementMinutes'],
                'daily_limit_hours' => $values['dailyLimitHours'],
                'retroactive_window_days' => $values['retroactiveWindowDays'],
                'comment_required' => $values['commentRequired'],
                'effective_from' => Date::now(),
            ]);

            $this->audit->record($tenant, 'settings.policy_updated', Policy::class, $policy->id, $actor, null, [
                'version' => $version,
                'before' => $before,
                'after' => $this->presentPolicy($policy),
            ]);

            return $policy;
        });
    }

    public function setProjectEnabled(Tenant $tenant, Member $actor, int $projectId, bool $enabled): void
    {
        $project = Project::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);

        if ($project->is_enabled === $enabled) {
            return;
        }

        $project->update(['is_enabled' => $enabled]);

        $this->audit->record($tenant, $enabled ? 'settings.project_enabled' : 'settings.project_disabled', Project::class, $project->id, $actor, $project);
    }

    // ---------- atividades ----------

    /**
     * @param  array{name: string, color?: string|null, defaultBillable?: bool}  $values
     */
    public function createActivityType(Tenant $tenant, Member $actor, array $values): ActivityType
    {
        // Sem isto, criar a primeira atividade antes de qualquer listagem
        // impediria o conjunto padrão de ser semeado (só semeia com zero).
        $this->activityTypes->ensureDefaults($tenant);
        $this->assertActivityNameFree($tenant, $values['name']);

        $type = ActivityType::query()->create([
            'tenant_id' => $tenant->id,
            'name' => $values['name'],
            'color' => $values['color'] ?? null,
            'is_enabled' => true,
            'is_default_billable' => $values['defaultBillable'] ?? false,
        ]);

        $this->audit->record($tenant, 'settings.activity_type_created', ActivityType::class, $type->id, $actor, null, ['name' => $type->name]);

        return $type;
    }

    /**
     * Desabilitar esconde o tipo dos próximos lançamentos; os antigos o mantêm.
     *
     * @param  array{name?: string, color?: string|null, enabled?: bool, defaultBillable?: bool}  $values
     */
    public function updateActivityType(Tenant $tenant, Member $actor, int $typeId, array $values): ActivityType
    {
        $type = ActivityType::query()->where('tenant_id', $tenant->id)->findOrFail($typeId);

        if (isset($values['name']) && $values['name'] !== $type->name) {
            $this->assertActivityNameFree($tenant, $values['name'], $type->id);
        }

        $changes = [];
        foreach (['name' => 'name', 'color' => 'color', 'enabled' => 'is_enabled', 'defaultBillable' => 'is_default_billable'] as $input => $column) {
            if (array_key_exists($input, $values)) {
                $changes[$column] = $values[$input];
            }
        }

        $type->update($changes);

        $this->audit->record($tenant, 'settings.activity_type_updated', ActivityType::class, $type->id, $actor, null, ['changes' => array_keys($values)]);

        return $type;
    }

    // ---------- papéis ----------

    public function grantRole(Tenant $tenant, Member $actor, int $memberId, string $role, ?int $projectId): RoleAssignment
    {
        $member = Member::query()->where('tenant_id', $tenant->id)->findOrFail($memberId);
        $project = $projectId === null ? null : Project::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);

        $assignment = RoleAssignment::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'project_id' => $project?->id,
            'role' => $role,
        ]);

        if ($assignment->wasRecentlyCreated) {
            $this->audit->record($tenant, 'role.granted', RoleAssignment::class, $assignment->id, $actor, $project, [
                'memberId' => $member->id,
                'role' => $role,
            ]);
        }

        return $assignment;
    }

    public function revokeRole(Tenant $tenant, Member $actor, int $assignmentId): void
    {
        DB::transaction(function () use ($tenant, $actor, $assignmentId) {
            // Travar o tenant evita que dois administradores se removam ao mesmo tempo.
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->first();

            $assignment = RoleAssignment::query()->where('tenant_id', $tenant->id)->findOrFail($assignmentId);

            if ($assignment->role === RoleAssignment::ROLE_ADMIN && $assignment->project_id === null) {
                $otherAdmins = RoleAssignment::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('role', RoleAssignment::ROLE_ADMIN)
                    ->whereNull('project_id')
                    ->where('id', '!=', $assignment->id)
                    ->exists();

                if (! $otherAdmins) {
                    throw new ConflictException('Não é possível remover o último administrador da organização.');
                }
            }

            $assignment->delete();

            $this->audit->record($tenant, 'role.revoked', RoleAssignment::class, $assignment->id, $actor, null, [
                'memberId' => $assignment->member_id,
                'role' => $assignment->role,
                'projectId' => $assignment->project_id,
            ]);
        });
    }

    // ---------- aprovadores ----------

    public function designateApprover(Tenant $tenant, Member $actor, int $memberId, int $approverId, ?int $projectId, bool $applyToPending): ApproverAssignment
    {
        $member = Member::query()->where('tenant_id', $tenant->id)->findOrFail($memberId);
        $approver = Member::query()->where('tenant_id', $tenant->id)->findOrFail($approverId);
        $project = $projectId === null ? null : Project::query()->where('tenant_id', $tenant->id)->findOrFail($projectId);

        if ($member->id === $approver->id) {
            throw ValidationException::withMessages(['approverId' => 'Uma pessoa não pode ser aprovadora da própria semana.']);
        }

        $assignment = ApproverAssignment::query()->firstOrCreate([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'project_id' => $project?->id,
            'approver_id' => $approver->id,
        ]);

        if ($assignment->wasRecentlyCreated) {
            $this->audit->record($tenant, 'approver.designated', ApproverAssignment::class, $assignment->id, $actor, $project, [
                'memberId' => $member->id,
                'approverId' => $approver->id,
            ]);
        }

        if ($applyToPending) {
            $this->refreshPendingApprovers($tenant, $actor, $member);
        }

        return $assignment;
    }

    public function removeDesignation(Tenant $tenant, Member $actor, int $assignmentId, bool $applyToPending): void
    {
        $assignment = ApproverAssignment::query()->where('tenant_id', $tenant->id)->findOrFail($assignmentId);
        $member = Member::query()->findOrFail($assignment->member_id);

        $assignment->delete();

        $this->audit->record($tenant, 'approver.removed', ApproverAssignment::class, $assignment->id, $actor, null, [
            'memberId' => $assignment->member_id,
            'approverId' => $assignment->approver_id,
        ]);

        if ($applyToPending) {
            $this->refreshPendingApprovers($tenant, $actor, $member);
        }
    }

    /**
     * Reatribui as semanas JÁ enviadas e ainda pendentes da pessoa às
     * designações de hoje (a resolução normal acontece só no envio). Decisões
     * já tomadas não mudam.
     */
    public function refreshPendingApprovers(Tenant $tenant, Member $actor, Member $member): int
    {
        return DB::transaction(function () use ($tenant, $actor, $member) {
            $pending = WeeklySubmission::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('status', WeeklySubmission::STATUS_SUBMITTED)
                ->lockForUpdate()
                ->get();

            foreach ($pending as $submission) {
                $projectIds = TimeEntry::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('member_id', $member->id)
                    ->whereBetween('local_date', [$submission->week_start_date, WeekCalendar::endOf($submission->week_start_date)])
                    ->distinct()
                    ->pluck('project_id')
                    ->map(fn ($id) => (int) $id)
                    ->all();

                $submission->approverRows()->where('revision', $submission->revision)->delete();

                foreach ($this->approvers->designatedFor($tenant, $member, $projectIds) as $approverId) {
                    $submission->approverRows()->create(['revision' => $submission->revision, 'approver_id' => $approverId]);
                }
            }

            if ($pending->isNotEmpty()) {
                $this->audit->record($tenant, 'approver.reassigned', WeeklySubmission::class, null, $actor, null, [
                    'memberId' => $member->id,
                    'submissionIds' => $pending->pluck('id')->all(),
                ]);
            }

            return $pending->count();
        });
    }

    // ---------- helpers ----------

    private function currentPolicy(Tenant $tenant): ?Policy
    {
        return Policy::query()
            ->where('tenant_id', $tenant->id)
            ->whereNull('project_id')
            ->where('effective_from', '<=', Date::now())
            ->orderByDesc('version')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPolicy(?Policy $policy): array
    {
        if ($policy === null) {
            return self::POLICY_DEFAULTS + ['version' => 0, 'effectiveFrom' => null];
        }

        return [
            'durationIncrementMinutes' => $policy->duration_increment_minutes,
            'dailyLimitHours' => $policy->daily_limit_hours,
            'retroactiveWindowDays' => $policy->retroactive_window_days,
            'commentRequired' => $policy->comment_required,
            'version' => $policy->version,
            'effectiveFrom' => $policy->effective_from->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentActivityType(ActivityType $type): array
    {
        return [
            'id' => (string) $type->id,
            'name' => $type->name,
            'color' => $type->color,
            'enabled' => $type->is_enabled,
            'defaultBillable' => $type->is_default_billable,
        ];
    }

    private function assertActivityNameFree(Tenant $tenant, string $name, ?int $exceptId = null): void
    {
        $taken = ActivityType::query()
            ->where('tenant_id', $tenant->id)
            ->where('name', $name)
            ->when($exceptId !== null, fn ($query) => $query->where('id', '!=', $exceptId))
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages(['name' => 'Já existe uma atividade com esse nome.']);
        }
    }
}
