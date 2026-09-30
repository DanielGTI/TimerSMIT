<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Member;
use App\Models\Policy;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Lançamento manual (US1, T017). Regras vêm da política vigente do projeto
 * (ou do tenant, se o projeto não tiver uma própria) — sem nenhuma política
 * cadastrada ainda (tenant novo, antes de US5/configuração), usa os mesmos
 * padrões da migração de `policies` (comment_required=false,
 * daily_limit_hours=24, retroactive_window_days=30).
 */
class TimeEntryService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly WeekLockGuard $weeks,
    ) {}

    public function createManual(
        Tenant $tenant,
        Member $member,
        Project $project,
        int $devopsWorkItemId,
        string $localDate,
        int $durationSeconds,
        ?int $activityTypeId,
        ?bool $billable,
        ?string $note,
    ): TimeEntry {
        $policy = $this->effectivePolicy($tenant, $project);
        $commentRequired = $policy->comment_required ?? false;
        $dailyLimitSeconds = ($policy->daily_limit_hours ?? 24) * 3600;
        $retroactiveWindowDays = $policy->retroactive_window_days ?? 30;
        $timezone = $tenant->default_timezone ?: 'UTC';

        if ($durationSeconds <= 0) {
            throw ValidationException::withMessages(['durationSeconds' => 'Duração deve ser maior que zero.']);
        }

        if ($commentRequired && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Comentário obrigatório para lançamentos manuais nesta organização.']);
        }

        $entryDate = CarbonImmutable::parse($localDate, $timezone)->startOfDay();
        $today = CarbonImmutable::now($timezone)->startOfDay();

        if ($entryDate->lessThan($today->subDays($retroactiveWindowDays))) {
            throw ValidationException::withMessages(['localDate' => 'Data fora da janela permitida para lançamento retroativo.']);
        }

        return DB::transaction(function () use (
            $tenant, $member, $project, $devopsWorkItemId, $localDate, $durationSeconds,
            $activityTypeId, $billable, $note, $timezone, $dailyLimitSeconds,
        ) {
            // Trava a linha do membro (e confere se a semana aceita edição).
            // Sem isso, duas requisições simultâneas leem o mesmo total do
            // dia e as duas passam do limite; o PostgreSQL não permite
            // FOR UPDATE junto de sum(), e travar só as linhas existentes
            // não impediria dois INSERTs concorrentes.
            $this->weeks->assertEditable($tenant, $member, [$localDate]);

            $existingSeconds = (int) TimeEntry::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('local_date', $localDate)
                ->sum('duration_seconds');

            if ($existingSeconds + $durationSeconds > $dailyLimitSeconds) {
                throw ValidationException::withMessages(['durationSeconds' => 'Limite diário de horas excedido para esta data.']);
            }

            $entry = TimeEntry::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'member_id' => $member->id,
                'devops_work_item_id' => $devopsWorkItemId,
                'timer_session_id' => null,
                'activity_type_id' => $activityTypeId,
                'local_date' => $localDate,
                'timezone' => $timezone,
                'duration_seconds' => $durationSeconds,
                'source' => TimeEntry::SOURCE_MANUAL,
                'billable' => $billable ?? true,
                'note' => $note,
                'revision' => 1,
            ]);

            $this->audit->record(
                tenant: $tenant,
                action: 'time_entry.created',
                subjectType: TimeEntry::class,
                subjectId: $entry->id,
                actor: $member,
                project: $project,
                context: ['source' => 'manual'],
            );

            return $entry;
        });
    }

    /**
     * @param  array{durationSeconds?: int, note?: string, billable?: bool}  $changes
     */
    public function update(Tenant $tenant, Member $member, int $entryId, int $expectedRevision, array $changes): TimeEntry
    {
        return DB::transaction(function () use ($tenant, $member, $entryId, $expectedRevision, $changes) {
            $entry = $this->lockOwnEditableEntry($tenant, $member, $entryId);

            if ($entry->revision !== $expectedRevision) {
                throw new ConflictException('Revisão divergente — recarregue o lançamento antes de editar.');
            }

            if (isset($changes['durationSeconds']) && $changes['durationSeconds'] <= 0) {
                throw ValidationException::withMessages(['durationSeconds' => 'Duração deve ser maior que zero.']);
            }

            $entry->update([
                'duration_seconds' => $changes['durationSeconds'] ?? $entry->duration_seconds,
                'note' => $changes['note'] ?? $entry->note,
                'billable' => $changes['billable'] ?? $entry->billable,
                'revision' => $entry->revision + 1,
            ]);

            $this->audit->record(
                tenant: $tenant,
                action: 'time_entry.updated',
                subjectType: TimeEntry::class,
                subjectId: $entry->id,
                actor: $member,
                project: $entry->project,
                context: ['changes' => array_keys($changes)],
            );

            return $entry;
        });
    }

    public function delete(Tenant $tenant, Member $member, int $entryId): void
    {
        DB::transaction(function () use ($tenant, $member, $entryId) {
            $entry = $this->lockOwnEditableEntry($tenant, $member, $entryId);

            $entry->delete();

            $this->audit->record(
                tenant: $tenant,
                action: 'time_entry.deleted',
                subjectType: TimeEntry::class,
                subjectId: $entry->id,
                actor: $member,
                project: $entry->project,
            );
        });
    }

    /**
     * Só o dono edita/exclui o próprio lançamento (FR-004): o de outra pessoa
     * responde 404, igual a um id inexistente. Confere o bloqueio da semana
     * antes de travar o lançamento (mesma ordem do envio: membro primeiro).
     */
    private function lockOwnEditableEntry(Tenant $tenant, Member $member, int $entryId): TimeEntry
    {
        $own = fn () => TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('id', $entryId);

        $entry = $own()->first();

        if (! $entry) {
            abort(404);
        }

        $this->weeks->assertEditable($tenant, $member, [$entry->local_date]);

        return $own()->lockForUpdate()->firstOrFail();
    }

    private function effectivePolicy(Tenant $tenant, Project $project): ?Policy
    {
        return Policy::query()
            ->where('tenant_id', $tenant->id)
            ->where(function ($query) use ($project) {
                $query->whereNull('project_id')->orWhere('project_id', $project->id);
            })
            ->where('effective_from', '<=', Date::now())
            ->orderByRaw('project_id is null')
            ->orderByDesc('version')
            ->first();
    }
}
