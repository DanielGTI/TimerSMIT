<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\Member;
use App\Models\OvertimeRequest;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Um timer ativo por membro por tenant (US1, T015). "Fechar" nunca reabre a
 * sessão — só gera uma ou mais fatias diárias via TimeSplitService (T016).
 *
 * Idempotência cobre o `start`: repetir a mesma chamada com a mesma
 * Idempotency-Key devolve o mesmo timer, nunca cria outro. O `stop` usa
 * bloqueio de linha (`lockForUpdate`) para fechar a janela de corrida entre
 * duas chamadas concorrentes — a segunda encontra o timer já parado e
 * recebe 409, conforme contracts/openapi.yaml.
 */
class TimerService
{
    public function __construct(
        private readonly TimeSplitService $splitter,
        private readonly AuditService $audit,
        private readonly WeekLockGuard $weeks,
        private readonly OvertimeCoverageService $coverage,
        private readonly OvertimeRequestService $overtimeRequests,
    ) {}

    public function start(
        Tenant $tenant,
        Member $member,
        Project $project,
        int $devopsWorkItemId,
        ?int $activityTypeId,
        string $idempotencyKey,
    ): TimerSession {
        $existing = TimerSession::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing) {
            return $existing;
        }

        return DB::transaction(function () use ($tenant, $member, $project, $devopsWorkItemId, $activityTypeId, $idempotencyKey) {
            $activeExists = TimerSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('status', TimerSession::STATUS_ACTIVE)
                ->lockForUpdate()
                ->exists();

            if ($activeExists) {
                throw new ConflictException('Já existe timer ativo para outro item.');
            }

            try {
                $timer = TimerSession::query()->create([
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'member_id' => $member->id,
                    'devops_work_item_id' => $devopsWorkItemId,
                    'activity_type_id' => $activityTypeId,
                    'status' => TimerSession::STATUS_ACTIVE,
                    'started_at_utc' => Date::now(),
                    'idempotency_key' => $idempotencyKey,
                ]);
            } catch (UniqueConstraintViolationException $exception) {
                // Índice parcial de timer_sessions é a fonte final da
                // verdade: se duas requisições concorrentes passaram pelo
                // lockForUpdate acima (bancos diferentes/replicas, retry),
                // o banco ainda assim rejeita a segunda.
                throw new ConflictException('Já existe timer ativo para outro item.', previous: $exception);
            }

            $this->audit->record(
                tenant: $tenant,
                action: 'timer.started',
                subjectType: TimerSession::class,
                subjectId: $timer->id,
                actor: $member,
                project: $project,
                context: ['devopsWorkItemId' => $devopsWorkItemId],
            );

            return $timer;
        });
    }

    /**
     * Perfil restrito: o trecho fora do expediente sem pedido aprovado vira hora
     * extra a confirmar, não lançamento; sem motivo e ciência, o timer não para
     * (422 em `overtimeReason`) e a pessoa informa os dois.
     *
     * @return array{entries: Collection<int, TimeEntry>, pending: list<OvertimeRequest>}
     */
    public function stop(
        Tenant $tenant,
        Member $member,
        int $timerId,
        ?string $note,
        ?bool $billable,
        ?string $overtimeReason = null,
        bool $overtimeAcknowledged = false,
    ): array {
        return DB::transaction(function () use ($tenant, $member, $timerId, $note, $billable, $overtimeReason, $overtimeAcknowledged) {
            $timer = TimerSession::query()
                ->where('tenant_id', $tenant->id)
                ->where('member_id', $member->id)
                ->where('id', $timerId)
                ->lockForUpdate()
                ->first();

            if (! $timer) {
                abort(404);
            }

            if (! $timer->isActive()) {
                throw new ConflictException('Timer já fechado por outra operação ou semana bloqueada.');
            }

            // Sem microssegundos: o que é gravado em ended_at_utc e a soma
            // das fatias precisam concordar ao segundo.
            $endedAt = Date::now()->startOfSecond();

            $timezone = $tenant->default_timezone ?: 'UTC';
            $slices = $this->splitter->split($timer->started_at_utc, $endedAt, $timezone);

            // Antes de gravar qualquer coisa: se alguma das datas cai numa
            // semana já enviada/aprovada, o timer continua ativo e a resposta
            // é 409 (contracts/openapi.yaml).
            $this->weeks->assertEditable($tenant, $member, array_column($slices, 'localDate'));

            $timer->update([
                'status' => TimerSession::STATUS_STOPPED,
                'ended_at_utc' => $endedAt,
                'note' => $note ?? $timer->note,
                'billable' => $billable ?? $timer->billable,
            ]);

            // Cada fatia começa onde a anterior terminou (a primeira, no início do timer).
            $cursor = CarbonImmutable::instance($timer->started_at_utc)->utc();
            $pieces = [];
            foreach ($slices as $slice) {
                $local = $cursor->setTimezone($timezone);
                $startSeconds = $local->hour * 3600 + $local->minute * 60 + $local->second;
                $plan = $this->coverage->plan($tenant, $member, $slice['localDate'], $local->format('H:i:s'), $slice['durationSeconds'], $timezone);
                $pieces[] = ['slice' => $slice, 'start' => $cursor, 'startSeconds' => $startSeconds, 'plan' => $plan];
                $cursor = $cursor->addSeconds($slice['durationSeconds']);
            }

            $hasPending = collect($pieces)->contains(fn (array $piece) => $piece['plan']['pending'] !== []);
            if ($hasPending && (! $overtimeAcknowledged || trim((string) $overtimeReason) === '')) {
                throw ValidationException::withMessages(['overtimeReason' => TimeEntryService::RESTRICTED_MESSAGE]);
            }

            $entries = collect();
            $pending = [];
            foreach ($pieces as $piece) {
                $slice = $piece['slice'];
                $parts = $piece['plan']['pending'] === []
                    ? [['start' => $piece['startSeconds'], 'seconds' => $slice['durationSeconds'], 'utc' => $piece['start']]]
                    : array_map(fn (array $part) => $part + ['utc' => CarbonImmutable::parse($slice['localDate'], $timezone)->startOfDay()->addSeconds($part['start'])->utc()], $piece['plan']['normal']);

                foreach ($parts as $part) {
                    $entry = TimeEntry::query()->create([
                        'tenant_id' => $tenant->id,
                        'project_id' => $timer->project_id,
                        'member_id' => $timer->member_id,
                        'devops_work_item_id' => $timer->devops_work_item_id,
                        'timer_session_id' => $timer->id,
                        'activity_type_id' => $timer->activity_type_id,
                        'local_date' => $slice['localDate'],
                        'timezone' => $timezone,
                        'duration_seconds' => $part['seconds'],
                        'started_at_utc' => $part['utc'],
                        'ended_at_utc' => $part['utc']->addSeconds($part['seconds']),
                        'source' => TimeEntry::SOURCE_TIMER,
                        'billable' => $timer->billable ?? true,
                        'note' => $timer->note,
                        'revision' => 1,
                    ]);

                    $this->audit->record(
                        tenant: $tenant,
                        action: 'time_entry.created',
                        subjectType: TimeEntry::class,
                        subjectId: $entry->id,
                        actor: $timer->member,
                        project: $timer->project,
                        context: ['source' => 'timer', 'timerSessionId' => $timer->id],
                    );

                    $entries->push($entry);
                }

                foreach ($piece['plan']['pending'] as $part) {
                    $pending[] = $this->overtimeRequests->createConfirmation($tenant, $member, [
                        'localDate' => $slice['localDate'],
                        'startTime' => TimeEntryService::clock($part['start']),
                        'endTime' => TimeEntryService::clock($part['end']),
                        'seconds' => $part['seconds'],
                        'projectId' => $timer->project_id,
                        'workItemId' => $timer->devops_work_item_id,
                        'activityTypeId' => $timer->activity_type_id,
                        'note' => $timer->note,
                        'billable' => (bool) ($timer->billable ?? false),
                        'timezone' => $timezone,
                        'source' => TimeEntry::SOURCE_TIMER,
                        'reason' => (string) $overtimeReason,
                    ]);
                }
            }

            $this->audit->record(
                tenant: $tenant,
                action: 'timer.stopped',
                subjectType: TimerSession::class,
                subjectId: $timer->id,
                actor: $member,
                project: $timer->project,
                context: ['entryCount' => $entries->count(), 'pendingOvertimeCount' => count($pending)],
            );

            return ['entries' => $entries, 'pending' => $pending];
        });
    }
}
