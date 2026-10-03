<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Member;
use App\Models\Policy;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Support\WeekCalendar;
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
        private readonly OvertimeRuleService $overtime,
        private readonly OvertimeCoverageService $coverage,
        private readonly OvertimeRequestService $overtimeRequests,
    ) {}

    /** Recado do perfil restrito quando falta o motivo/ciência da hora extra a confirmar. */
    public const RESTRICTED_MESSAGE = 'Pelo seu perfil, a hora fora do expediente sem pedido aprovado vira hora extra a confirmar: ela só conta se o aprovador confirmar. Informe o motivo e marque que entendeu.';

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
        ?string $startTime = null,
        ?string $overtimeReason = null,
        bool $overtimeAcknowledged = false,
    ): array {
        $policy = $this->effectivePolicy($tenant, $project);
        $commentRequired = $policy->comment_required ?? false;
        $dailyLimitSeconds = ($policy->daily_limit_hours ?? 24) * 3600;
        $retroactiveWindowDays = $policy->retroactive_window_days ?? 30;
        $timezone = $tenant->default_timezone ?: 'UTC';

        if ($durationSeconds <= 0) {
            throw ValidationException::withMessages(['durationSeconds' => 'Duração deve ser maior que zero.']);
        }

        $this->assertIncrement($policy, $durationSeconds);
        $this->assertMinimum($policy, $durationSeconds);

        // Sem horário não dá para saber o que foi fora do expediente (hora adicional).
        if ($startTime === null && $this->overtime->requiresTimeOfDay($tenant, $member)) {
            throw ValidationException::withMessages(['startTime' => 'Informe o horário (De/Até): ele é necessário para separar o expediente das horas adicionais.']);
        }

        $window = $startTime === null ? null : $this->window($localDate, $startTime, $durationSeconds, $timezone);

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
            $activityTypeId, $billable, $note, $timezone, $dailyLimitSeconds, $window, $startTime,
            $overtimeReason, $overtimeAcknowledged,
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

            // Perfil restrito: o trecho fora do expediente sem pedido aprovado não vira
            // lançamento; vira hora extra a confirmar (com motivo e ciência da pessoa).
            $plan = $this->coverage->plan($tenant, $member, $localDate, $startTime, $durationSeconds, $timezone);

            if ($plan['pending'] !== [] && (! $overtimeAcknowledged || trim((string) $overtimeReason) === '')) {
                throw ValidationException::withMessages(['overtimeReason' => self::RESTRICTED_MESSAGE]);
            }

            $entries = [];
            foreach ($plan['pending'] === [] ? [null] : $plan['normal'] as $piece) {
                $times = $piece === null
                    ? ['started_at_utc' => $window[0] ?? null, 'ended_at_utc' => $window[1] ?? null]
                    : $this->pieceTimes($localDate, $piece, $timezone);

                $entry = TimeEntry::query()->create($times + [
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'member_id' => $member->id,
                    'devops_work_item_id' => $devopsWorkItemId,
                    'timer_session_id' => null,
                    'activity_type_id' => $activityTypeId,
                    'local_date' => $localDate,
                    'timezone' => $timezone,
                    'duration_seconds' => $piece['seconds'] ?? $durationSeconds,
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

                $entries[] = $entry;
            }

            $pending = array_map(fn (array $piece) => $this->overtimeRequests->createConfirmation($tenant, $member, [
                'localDate' => $localDate,
                'startTime' => $piece['start'] === null ? null : self::clock($piece['start']),
                'endTime' => $piece['end'] === null ? null : self::clock($piece['end']),
                'seconds' => $piece['seconds'],
                'projectId' => $project->id,
                'workItemId' => $devopsWorkItemId,
                'activityTypeId' => $activityTypeId,
                'note' => $note,
                'billable' => (bool) ($billable ?? false),
                'timezone' => $timezone,
                'source' => TimeEntry::SOURCE_MANUAL,
                'reason' => (string) $overtimeReason,
            ]), $plan['pending']);

            return ['entries' => $entries, 'pending' => $pending];
        });
    }

    /**
     * @param  array{durationSeconds?: int, note?: string, billable?: bool, startTime?: ?string}  $changes
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

            // Editar não pode ser um atalho para burlar as regras vigentes.
            // Incremento e comentário valem para lançamento manual (o do timer
            // tem duração exata em segundos e comentário opcional); o limite
            // diário vale para qualquer origem.
            $policy = $this->effectivePolicy($tenant, $entry->project);
            $newDuration = $changes['durationSeconds'] ?? $entry->duration_seconds;
            $newNote = $changes['note'] ?? $entry->note;

            if ($entry->source === TimeEntry::SOURCE_MANUAL) {
                if (isset($changes['durationSeconds'])) {
                    $this->assertIncrement($policy, $newDuration);
                    $this->assertMinimum($policy, $newDuration);
                }

                if (($policy->comment_required ?? false) && trim((string) $newNote) === '') {
                    throw ValidationException::withMessages(['note' => 'Comentário obrigatório para lançamentos manuais nesta organização.']);
                }
            }

            if (isset($changes['durationSeconds'])) {
                $others = (int) TimeEntry::query()
                    ->where('tenant_id', $tenant->id)
                    ->where('member_id', $member->id)
                    ->where('local_date', $entry->local_date)
                    ->where('id', '!=', $entry->id)
                    ->sum('duration_seconds');

                if ($others + $newDuration > ($policy->daily_limit_hours ?? 24) * 3600) {
                    throw ValidationException::withMessages(['durationSeconds' => 'Limite diário de horas excedido para esta data.']);
                }
            }

            // Horário: informar o início (ou null para apagar) e mudar a duração
            // mantendo o início movem o fim; nunca pode passar da meia-noite local.
            if (
                array_key_exists('startTime', $changes)
                && $changes['startTime'] === null
                && $entry->source === TimeEntry::SOURCE_MANUAL
                && $this->overtime->requiresTimeOfDay($tenant, $member)
            ) {
                throw ValidationException::withMessages(['startTime' => 'Informe o horário (De/Até): ele é necessário para separar o expediente das horas adicionais.']);
            }

            // Perfil restrito: a edição não pode levar o lançamento para fora do expediente sem pedido.
            if (isset($changes['durationSeconds']) || array_key_exists('startTime', $changes)) {
                $newStart = array_key_exists('startTime', $changes) ? $changes['startTime'] : $entry->localStartTime();
                $plan = $this->coverage->plan($tenant, $member, substr((string) $entry->local_date, 0, 10), $newStart, $newDuration, $entry->timezone ?: 'UTC', $entry->id);

                if ($plan['pending'] !== []) {
                    throw ValidationException::withMessages(['startTime' => 'Pelo seu perfil, hora fora do expediente sem pedido aprovado só entra como hora extra a confirmar. Mantenha este lançamento dentro do expediente e lance o restante pelo “Adicionar tempo”.']);
                }
            }

            $times = [];
            if (array_key_exists('startTime', $changes)) {
                $times = $changes['startTime'] === null
                    ? ['started_at_utc' => null, 'ended_at_utc' => null]
                    : $this->windowColumns($entry->local_date, $changes['startTime'], $newDuration, $entry->timezone);
            } elseif (isset($changes['durationSeconds']) && $entry->started_at_utc !== null) {
                $times = $this->windowColumns($entry->local_date, $entry->localStartTime(), $newDuration, $entry->timezone);
            }

            $entry->update($times + [
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

    /**
     * Correção de lançamento de qualquer pessoa pelo administrador (relatório
     * detalhado). Pode mudar data, horário, duração, work item, atividade,
     * comentário e faturável. Não passa pela janela retroativa, pelo
     * incremento nem pelo comentário obrigatório (é correção, não lançamento
     * novo), mas vale o que mantém os dados coerentes: semana aberta (na data
     * antiga e na nova), limite diário da pessoa e intervalo dentro do dia.
     *
     * @param  array{localDate?: string, startTime?: ?string, durationSeconds?: int, activityTypeId?: ?int, note?: ?string, billable?: bool, workItemId?: int}  $changes
     */
    public function adminUpdate(
        Tenant $tenant,
        Member $admin,
        int $entryId,
        int $expectedRevision,
        array $changes,
        ?Project $project = null,
    ): TimeEntry {
        return DB::transaction(function () use ($tenant, $admin, $entryId, $expectedRevision, $changes, $project) {
            $entry = $this->lockEntryForAdmin($tenant, $entryId, [$changes['localDate'] ?? null]);

            if ($entry->revision !== $expectedRevision) {
                throw new ConflictException('Este lançamento mudou desde que o relatório foi carregado. Atualize e tente de novo.');
            }

            $member = $entry->member;
            $project ??= $entry->project;
            $localDate = $changes['localDate'] ?? substr((string) $entry->local_date, 0, 10);
            $duration = $changes['durationSeconds'] ?? $entry->duration_seconds;
            $startTime = array_key_exists('startTime', $changes) ? $changes['startTime'] : $entry->localStartTime();

            if ($duration <= 0) {
                throw ValidationException::withMessages(['durationSeconds' => 'Duração deve ser maior que zero.']);
            }

            if (
                array_key_exists('startTime', $changes)
                && $changes['startTime'] === null
                && $entry->started_at_utc !== null
                && $this->overtime->requiresTimeOfDay($tenant, $member)
            ) {
                throw ValidationException::withMessages(['startTime' => 'Informe o horário (De/Até): ele é necessário para separar o expediente das horas adicionais.']);
            }

            $others = (int) TimeEntry::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('local_date', $localDate)
                ->where('id', '!=', $entry->id)
                ->sum('duration_seconds');

            $policy = $this->effectivePolicy($tenant, $project);
            if ($others + $duration > ($policy->daily_limit_hours ?? 24) * 3600) {
                throw ValidationException::withMessages(['durationSeconds' => 'Limite diário de horas excedido para esta pessoa nesta data.']);
            }

            $times = $startTime === null
                ? ['started_at_utc' => null, 'ended_at_utc' => null]
                : $this->windowColumns($localDate, $startTime, $duration, $entry->timezone);

            $before = $this->auditView($entry);

            $entry->update($times + [
                'local_date' => $localDate,
                'week_start_date' => WeekCalendar::startOf($localDate),
                'project_id' => $project->id,
                'devops_work_item_id' => $changes['workItemId'] ?? $entry->devops_work_item_id,
                'duration_seconds' => $duration,
                'activity_type_id' => array_key_exists('activityTypeId', $changes) ? $changes['activityTypeId'] : $entry->activity_type_id,
                'note' => array_key_exists('note', $changes) ? $changes['note'] : $entry->note,
                'billable' => $changes['billable'] ?? $entry->billable,
                'revision' => $entry->revision + 1,
            ]);

            $this->audit->record(
                tenant: $tenant,
                action: 'time_entry.admin_updated',
                subjectType: TimeEntry::class,
                subjectId: $entry->id,
                actor: $admin,
                project: $project,
                context: [
                    'memberId' => $member->id,
                    'changes' => array_keys($changes),
                    'before' => $before,
                    'after' => $this->auditView($entry->refresh()),
                ],
            );

            return $entry;
        });
    }

    /** Exclusão de lançamento de qualquer pessoa pelo administrador (semana aberta). */
    public function adminDelete(Tenant $tenant, Member $admin, int $entryId): void
    {
        DB::transaction(function () use ($tenant, $admin, $entryId) {
            $entry = $this->lockEntryForAdmin($tenant, $entryId);

            $before = $this->auditView($entry);
            $entry->delete();

            $this->audit->record(
                tenant: $tenant,
                action: 'time_entry.admin_deleted',
                subjectType: TimeEntry::class,
                subjectId: $entry->id,
                actor: $admin,
                project: $entry->project,
                context: ['memberId' => $entry->member_id, 'before' => $before],
            );
        });
    }

    /**
     * Lançamento de qualquer pessoa do tenant, travado, com a semana da data
     * atual (e da nova, se mudar) conferida.
     *
     * @param  array<int, ?string>  $extraDates
     */
    private function lockEntryForAdmin(Tenant $tenant, int $entryId, array $extraDates = []): TimeEntry
    {
        $entry = TimeEntry::query()->where('tenant_id', $tenant->id)->whereKey($entryId)->first();

        if (! $entry) {
            abort(404);
        }

        try {
            $this->weeks->assertEditable($tenant, $entry->member, array_filter([$entry->local_date, ...$extraDates]));
        } catch (ConflictException) {
            throw new ConflictException('A semana deste lançamento (ou a da nova data) foi enviada ou aprovada. Para corrigir, ela precisa voltar a ficar aberta: a pessoa recolhe o envio, o aprovador rejeita ou o administrador reabre em Aprovações.');
        }

        return TimeEntry::query()->whereKey($entry->id)->lockForUpdate()->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function auditView(TimeEntry $entry): array
    {
        return [
            'localDate' => $entry->local_date,
            'startTime' => $entry->localStartTime(),
            'durationSeconds' => $entry->duration_seconds,
            'projectId' => $entry->project_id,
            'workItemId' => $entry->devops_work_item_id,
            'activityTypeId' => $entry->activity_type_id,
            'billable' => $entry->billable,
            'note' => $entry->note,
        ];
    }

    /**
     * Início e fim em UTC de um trecho (segundos desde 00:00 local); sem início, sem horário.
     *
     * @param  array{start: ?int, end: ?int, seconds: int}  $piece
     * @return array{started_at_utc: ?CarbonImmutable, ended_at_utc: ?CarbonImmutable}
     */
    public function pieceTimes(string $localDate, array $piece, string $timezone): array
    {
        if ($piece['start'] === null) {
            return ['started_at_utc' => null, 'ended_at_utc' => null];
        }

        $start = CarbonImmutable::parse(substr($localDate, 0, 10), $timezone)->startOfDay()->addSeconds($piece['start']);

        return ['started_at_utc' => $start->utc(), 'ended_at_utc' => $start->addSeconds($piece['seconds'])->utc()];
    }

    /** Segundos desde 00:00 em "HH:MM" (ou "HH:MM:SS" quando há segundos). */
    public static function clock(int $seconds): string
    {
        $text = sprintf('%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));

        return $seconds % 60 === 0 ? $text : $text.sprintf(':%02d', $seconds % 60);
    }

    /**
     * Início e fim em UTC a partir da data e da hora locais. O intervalo tem de
     * caber no dia: um lançamento é de uma data só (passou da meia-noite? lance
     * em dois dias).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function window(string $localDate, string $startTime, int $durationSeconds, string $timezone): array
    {
        $start = CarbonImmutable::parse(substr($localDate, 0, 10).' '.$startTime, $timezone);
        $end = $start->addSeconds($durationSeconds);

        if ($end->greaterThan($start->startOfDay()->addDay())) {
            throw ValidationException::withMessages([
                'startTime' => 'O horário passa da meia-noite. Registre o que ficou para o dia seguinte em outro lançamento.',
            ]);
        }

        return [$start->utc(), $end->utc()];
    }

    /** @return array{started_at_utc: CarbonImmutable, ended_at_utc: CarbonImmutable} */
    private function windowColumns(string $localDate, string $startTime, int $durationSeconds, string $timezone): array
    {
        [$start, $end] = $this->window($localDate, $startTime, $durationSeconds, $timezone);

        return ['started_at_utc' => $start, 'ended_at_utc' => $end];
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

    /** Lançamento manual precisa ser múltiplo do incremento da política (padrão: 1 minuto). */
    private function assertIncrement(?Policy $policy, int $durationSeconds): void
    {
        $minutes = $policy->duration_increment_minutes ?? 1;

        if ($minutes > 1 && $durationSeconds % ($minutes * 60) !== 0) {
            throw ValidationException::withMessages(['durationSeconds' => "A duração deve ser múltipla de {$minutes} minutos."]);
        }
    }

    /** Duração mínima do lançamento manual (padrão: 1 minuto, ou seja, sem mínimo). */
    private function assertMinimum(?Policy $policy, int $durationSeconds): void
    {
        $minutes = $policy->min_duration_minutes ?? 1;

        if ($minutes > 1 && $durationSeconds < $minutes * 60) {
            throw ValidationException::withMessages(['durationSeconds' => "A duração mínima de um lançamento é de {$minutes} minutos."]);
        }
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
            ->orderByDesc('id')
            ->first();
    }
}
