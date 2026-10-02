<?php

namespace App\Services;

use App\Exceptions\ConflictException;
use App\Models\AdditionalHourReview;
use App\Models\Member;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Fila do administrador: horas adicionais de semanas JÁ APROVADAS, aguardando
 * o destino (hora extra, banco de horas ou a pagar). Sem aprovação da semana
 * não existe hora extra — por isso só entram lançamentos de semana aprovada.
 */
class AdditionalHoursClassificationService
{
    /** Valor de `classification` que desfaz a decisão e devolve a hora para "a validar". */
    public const PENDING = 'pending';

    public function __construct(
        private readonly AdditionalHoursService $additional,
        private readonly AuditService $audit,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function list(Tenant $tenant, string $from, string $to, ?int $memberId, string $status): array
    {
        $entries = $this->approvedEntries($tenant)
            ->whereBetween('local_date', [$from, $to])
            ->when($memberId !== null, fn ($query) => $query->where('member_id', $memberId))
            ->with(['project', 'member'])
            ->orderBy('local_date')
            ->orderBy('id')
            ->get();

        $evaluation = $this->additional->evaluate($tenant, $entries);
        $snapshots = $this->snapshots($tenant, $entries);

        $rows = [];
        foreach ($entries as $entry) {
            $item = $evaluation[$entry->id];
            $view = $this->additional->present($item);

            if ($view === null) {
                continue;
            }

            $classified = $view['status'] !== 'pending';
            if (($status === 'pending' && $classified) || ($status === 'classified' && ! $classified)) {
                continue;
            }

            $snapshot = $snapshots->get($entry->project_id.':'.$entry->devops_work_item_id);

            $rows[] = [
                'entryId' => (string) $entry->id,
                'memberId' => (string) $entry->member_id,
                'memberName' => $entry->member->display_name,
                'regime' => $item['regime'],
                'localDate' => substr((string) $entry->local_date, 0, 10),
                'startTime' => $entry->localStartTime(),
                'endTime' => $entry->localEndTime(),
                'projectName' => $entry->project->devops_project_name,
                'workItemId' => $entry->devops_work_item_id,
                'workItemTitle' => $snapshot?->title,
                'note' => $entry->note,
                'durationSeconds' => $entry->duration_seconds,
                'additional' => $view,
                'classifiedAt' => $item['review']?->classified_at?->toIso8601String(),
                'classificationNote' => $item['review']?->classification_note,
            ];
        }

        return $rows;
    }

    /**
     * @param  list<int>  $entryIds
     * @return int quantos lançamentos foram atualizados
     */
    public function classify(Tenant $tenant, Member $actor, array $entryIds, string $classification, ?string $note): int
    {
        $note = $note === null ? null : trim($note);

        return DB::transaction(function () use ($tenant, $actor, $entryIds, $classification, $note) {
            $entries = $this->approvedEntries($tenant)->whereIn('id', $entryIds)->lockForUpdate()->get();

            if ($entries->count() !== count(array_unique($entryIds))) {
                throw new ConflictException('Há lançamentos que não existem ou cuja semana ainda não foi aprovada. Só dá para classificar horas de semana aprovada.');
            }

            $evaluation = $this->additional->evaluate($tenant, $entries);

            $errors = [];
            foreach ($entries as $entry) {
                $item = $evaluation[$entry->id];

                if ($this->additional->present($item) === null) {
                    $errors[] = "O lançamento #{$entry->id} não tem hora adicional.";
                } elseif ($classification === AdditionalHourReview::BANK && $item['regime'] !== Member::REGIME_CLT) {
                    $errors[] = 'Banco de horas só vale para quem é CLT; para PJ as horas são sempre a pagar.';
                }
            }

            if ($errors !== []) {
                throw ValidationException::withMessages(['entryIds' => array_values(array_unique($errors))]);
            }

            $now = Date::now();

            foreach ($entries as $entry) {
                $item = $evaluation[$entry->id];
                $review = $item['review'] ?? new AdditionalHourReview([
                    'tenant_id' => $tenant->id,
                    'member_id' => $entry->member_id,
                    'time_entry_id' => $entry->id,
                ]);

                if ($classification === self::PENDING) {
                    $review->fill([
                        'classification' => null,
                        'additional_seconds' => null,
                        'weighted_seconds' => null,
                        'overtime_rule_id' => null,
                        'classified_by' => null,
                        'classified_at' => null,
                        'classification_note' => null,
                    ]);
                } else {
                    // Congela o que o cálculo deu agora: mudanças futuras de regra ou de
                    // feriados não alteram horas que o administrador já decidiu.
                    $review->fill([
                        'classification' => $classification,
                        'additional_seconds' => $item['result']->additionalSeconds,
                        'weighted_seconds' => $item['result']->weightedSeconds,
                        'overtime_rule_id' => $item['ruleId'],
                        'classified_by' => $actor->id,
                        'classified_at' => $now,
                        'classification_note' => $note === '' ? null : $note,
                    ]);
                }

                $review->save();
            }

            $this->audit->record($tenant, 'additional_hours.classified', AdditionalHourReview::class, null, $actor, null, [
                'classification' => $classification,
                'entryIds' => $entries->pluck('id')->all(),
                'note' => $note,
            ]);

            return $entries->count();
        });
    }

    /**
     * Lançamentos de semana aprovada.
     *
     * @return Builder<TimeEntry>
     */
    private function approvedEntries(Tenant $tenant)
    {
        return TimeEntry::query()
            ->where('time_entries.tenant_id', $tenant->id)
            ->whereExists(function ($query) use ($tenant) {
                $query->selectRaw('1')
                    ->from('weekly_submissions')
                    ->where('weekly_submissions.tenant_id', $tenant->id)
                    ->whereColumn('weekly_submissions.member_id', 'time_entries.member_id')
                    ->whereColumn('weekly_submissions.week_start_date', 'time_entries.week_start_date')
                    ->where('weekly_submissions.status', WeeklySubmission::STATUS_APPROVED);
            });
    }

    /**
     * @param  Collection<int, TimeEntry>  $entries
     * @return Collection<string, WorkItemSnapshot>
     */
    private function snapshots(Tenant $tenant, Collection $entries): Collection
    {
        if ($entries->isEmpty()) {
            return collect();
        }

        return WorkItemSnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('project_id', $entries->pluck('project_id')->unique())
            ->whereIn('devops_work_item_id', $entries->pluck('devops_work_item_id')->unique())
            ->orderByDesc('captured_at')
            ->orderByDesc('id')
            ->get()
            ->unique(fn (WorkItemSnapshot $snapshot) => $snapshot->project_id.':'.$snapshot->devops_work_item_id)
            ->keyBy(fn (WorkItemSnapshot $snapshot) => $snapshot->project_id.':'.$snapshot->devops_work_item_id);
    }
}
