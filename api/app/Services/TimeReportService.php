<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

/**
 * Relatórios de horas (US4). Tela e CSV saem da MESMA consulta base
 * (`baseQuery`): mesmo escopo de acesso, mesmos filtros, mesma ordem — é o que
 * garante linhas e totais iguais nos dois (SC-006).
 *
 * Filtros (array): from, to (obrigatórios, 'Y-m-d'), projectId, memberId,
 * workItemId, activityTypeId, billable (bool), status (estado da semana).
 */
class TimeReportService
{
    /** Faturável de fato: marcado no lançamento E o projeto usa a marcação (ver Project::uses_billable). */
    private const BILLABLE = '(time_entries.billable and projects.uses_billable)';

    public function scopeFor(Tenant $tenant, Member $member): ReportScope
    {
        $projectIds = RoleAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $member->id)
            ->whereIn('role', [RoleAssignment::ROLE_MANAGER, RoleAssignment::ROLE_ADMIN])
            ->pluck('project_id');

        return new ReportScope(
            memberId: $member->id,
            tenantWide: $projectIds->contains(null),
            managedProjectIds: $projectIds->filter()->map(fn ($id) => (int) $id)->unique()->values()->all(),
        );
    }

    /**
     * Filtros explícitos fora do escopo respondem 403 (em vez de silenciosamente
     * vazio), como no contrato: trocar parâmetros na URL não dá acesso a nada.
     *
     * @param  array<string, mixed>  $filters
     */
    public function assertFiltersAllowed(Tenant $tenant, ReportScope $scope, array $filters): void
    {
        if (isset($filters['memberId']) && $filters['memberId'] !== $scope->memberId && ! $scope->canSeeOthers()) {
            throw new AuthorizationException;
        }

        if (isset($filters['projectId']) && ! in_array($filters['projectId'], $this->visibleProjectIds($tenant, $scope), true)) {
            throw new AuthorizationException;
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function report(Tenant $tenant, ReportScope $scope, array $filters, int $page, int $perPage): array
    {
        $base = $this->baseQuery($tenant, $scope, $filters);

        $totals = (clone $base)->toBase()->selectRaw(
            'coalesce(sum(time_entries.duration_seconds), 0) as total_seconds, '.
            'coalesce(sum(case when '.self::BILLABLE.' then time_entries.duration_seconds else 0 end), 0) as billable_seconds, '.
            'count(*) as entry_count'
        )->first();

        $total = (int) $totals->entry_count;

        $rows = $this->rowsQuery($base, $tenant)->forPage($page, $perPage)->get()
            ->map(fn ($row) => $this->presentRow($row))
            ->all();

        return [
            'filters' => $filters,
            'scope' => ['level' => $scope->level(), 'canFilterByMember' => $scope->canSeeOthers()],
            'totals' => [
                'totalSeconds' => (int) $totals->total_seconds,
                'billableSeconds' => (int) $totals->billable_seconds,
                'nonBillableSeconds' => (int) $totals->total_seconds - (int) $totals->billable_seconds,
                'entryCount' => $total,
            ],
            'byMember' => $this->grouped($base, 'members.id', 'members.display_name', 'Sem nome'),
            'byProject' => $this->grouped($base, 'projects.id', 'projects.devops_project_name', 'Sem nome'),
            'byActivity' => $this->grouped($base, 'activity_types.id', 'activity_types.name', 'Não definido'),
            'rows' => $rows,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'lastPage' => max(1, (int) ceil($total / $perPage)),
            ],
        ];
    }

    /**
     * Mesmas linhas da tela, sem paginação, lidas aos poucos (CSV grande não
     * estoura memória).
     *
     * @param  array<string, mixed>  $filters
     * @return array{count: int, totalSeconds: int, rows: LazyCollection<int, array<string, mixed>>}
     */
    public function exportRows(Tenant $tenant, ReportScope $scope, array $filters): array
    {
        $base = $this->baseQuery($tenant, $scope, $filters);

        $totals = (clone $base)->toBase()->selectRaw(
            'coalesce(sum(time_entries.duration_seconds), 0) as total_seconds, count(*) as entry_count'
        )->first();

        return [
            'count' => (int) $totals->entry_count,
            'totalSeconds' => (int) $totals->total_seconds,
            'rows' => $this->keysetRows($base, $tenant),
        ];
    }

    /**
     * Todas as linhas do filtro de uma vez (até `$limit`), para a grade
     * detalhada: mesma consulta, escopo e ordem da tela paginada e do CSV.
     *
     * @param  array<string, mixed>  $filters
     * @return array{scope: array<string, mixed>, totals: array<string, int>, rows: list<array<string, mixed>>, truncated: bool}
     */
    public function detail(Tenant $tenant, ReportScope $scope, array $filters, int $limit): array
    {
        $base = $this->baseQuery($tenant, $scope, $filters);

        $totals = (clone $base)->toBase()->selectRaw(
            'coalesce(sum(time_entries.duration_seconds), 0) as total_seconds, '.
            'coalesce(sum(case when '.self::BILLABLE.' then time_entries.duration_seconds else 0 end), 0) as billable_seconds, '.
            'count(*) as entry_count'
        )->first();

        $rows = $this->keysetRows($base, $tenant)->take($limit + 1)->values()->all();
        $truncated = count($rows) > $limit;

        return [
            'filters' => $filters,
            'scope' => ['level' => $scope->level(), 'canFilterByMember' => $scope->canSeeOthers()],
            'totals' => [
                'totalSeconds' => (int) $totals->total_seconds,
                'billableSeconds' => (int) $totals->billable_seconds,
                'nonBillableSeconds' => (int) $totals->total_seconds - (int) $totals->billable_seconds,
                'entryCount' => (int) $totals->entry_count,
            ],
            'rows' => $truncated ? array_slice($rows, 0, $limit) : $rows,
            'truncated' => $truncated,
        ];
    }

    /**
     * Lê na mesma ordem da tela, em blocos, sempre a partir da última linha
     * entregue. OFFSET (`lazy()`/`chunk()`) refaz a ordenação do período
     * inteiro a cada bloco — custo quadrático: 50 mil linhas levaram mais de
     * 20 s; por chave, o custo é linear.
     *
     * @param  Builder<TimeEntry>  $base
     * @return LazyCollection<int, array<string, mixed>>
     */
    private function keysetRows(Builder $base, Tenant $tenant): LazyCollection
    {
        $blockSize = 1000;

        return LazyCollection::make(function () use ($base, $tenant, $blockSize) {
            $after = null;

            do {
                $query = $this->rowsQuery($base, $tenant)->limit($blockSize);
                if ($after !== null) {
                    $query->whereRaw('(time_entries.local_date, members.display_name, time_entries.id) > (?, ?, ?)', $after);
                }

                $block = $query->get();
                foreach ($block as $row) {
                    yield $this->presentRow($row);
                }

                $tail = $block->last();
                $after = $tail === null ? null : [substr((string) $tail->local_date, 0, 10), $tail->member_name, $tail->id];
            } while ($block->count() === $blockSize);
        });
    }

    /**
     * Opções dos filtros: só o que o escopo permite enxergar.
     *
     * @return array<string, mixed>
     */
    public function options(Tenant $tenant, ReportScope $scope): array
    {
        $projectIds = $this->visibleProjectIds($tenant, $scope);

        $entryMembers = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->when(! $scope->tenantWide, fn ($query) => $query->whereIn('project_id', $scope->managedProjectIds ?: [0]))
            ->distinct()
            ->pluck('member_id');

        $memberQuery = Member::query()->where('tenant_id', $tenant->id);
        if (! $scope->tenantWide) {
            $memberQuery->where(fn ($query) => $query->where('id', $scope->memberId)->orWhereIn('id', $entryMembers));
        }

        return [
            'scope' => ['level' => $scope->level(), 'canFilterByMember' => $scope->canSeeOthers()],
            // Nenhum projeto cobra por hora: a tela esconde filtro, totais e coluna de faturável.
            'billableInUse' => Project::query()->where('tenant_id', $tenant->id)->where('uses_billable', true)->exists(),
            'members' => $memberQuery->orderBy('display_name')->get(['id', 'display_name'])
                ->map(fn (Member $member) => ['id' => (string) $member->id, 'name' => $member->display_name])->all(),
            'projects' => Project::query()->where('tenant_id', $tenant->id)->whereIn('id', $projectIds)->orderBy('devops_project_name')
                ->get(['id', 'devops_project_name', 'uses_billable'])
                ->map(fn (Project $project) => [
                    'id' => (string) $project->id,
                    'name' => $project->devops_project_name,
                    'usesBillable' => (bool) $project->uses_billable,
                ])->all(),
            'activityTypes' => ActivityType::query()->where('tenant_id', $tenant->id)->orderBy('name')->get()
                ->map(fn (ActivityType $type) => ['id' => (string) $type->id, 'name' => $type->name, 'color' => $type->color, 'enabled' => $type->is_enabled])->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<TimeEntry>
     */
    private function baseQuery(Tenant $tenant, ReportScope $scope, array $filters): Builder
    {
        $query = TimeEntry::query()
            ->join('members', 'members.id', '=', 'time_entries.member_id')
            ->join('projects', 'projects.id', '=', 'time_entries.project_id')
            ->leftJoin('activity_types', 'activity_types.id', '=', 'time_entries.activity_type_id')
            ->leftJoin('weekly_submissions', function ($join) {
                $join->on('weekly_submissions.tenant_id', '=', 'time_entries.tenant_id')
                    ->on('weekly_submissions.member_id', '=', 'time_entries.member_id')
                    ->on('weekly_submissions.week_start_date', '=', 'time_entries.week_start_date');
            })
            ->where('time_entries.tenant_id', $tenant->id)
            ->whereBetween('time_entries.local_date', [$filters['from'], $filters['to']]);

        if (! $scope->tenantWide) {
            $query->where(function ($visible) use ($scope) {
                $visible->where('time_entries.member_id', $scope->memberId);

                if ($scope->managedProjectIds !== []) {
                    $visible->orWhereIn('time_entries.project_id', $scope->managedProjectIds);
                }
            });
        }

        if (isset($filters['projectId'])) {
            $query->where('time_entries.project_id', $filters['projectId']);
        }
        if (isset($filters['memberId'])) {
            $query->where('time_entries.member_id', $filters['memberId']);
        }
        if (isset($filters['workItemId'])) {
            $query->where('time_entries.devops_work_item_id', $filters['workItemId']);
        }
        if (isset($filters['activityTypeId'])) {
            $query->where('time_entries.activity_type_id', $filters['activityTypeId']);
        }
        if (isset($filters['billable'])) {
            $query->whereRaw(($filters['billable'] ? '' : 'not ').self::BILLABLE);
        }
        if (isset($filters['status'])) {
            $query->whereRaw("coalesce(weekly_submissions.status, 'open') = ?", [$filters['status']]);
        }

        return $query;
    }

    /**
     * @param  Builder<TimeEntry>  $base
     * @return Builder<TimeEntry>
     */
    private function rowsQuery(Builder $base, Tenant $tenant): Builder
    {
        // Retrato mais recente de cada work item (título, tipo, iteração): uma
        // junção única em vez de uma subconsulta por coluna e por linha.
        $ranked = DB::table('work_item_snapshots')
            ->where('tenant_id', $tenant->id)
            ->selectRaw('id, project_id, devops_work_item_id, row_number() over (partition by project_id, devops_work_item_id order by captured_at desc, id desc) as rn');
        $latest = DB::query()->fromSub($ranked, 'ranked')->where('rn', 1)->select('id', 'project_id', 'devops_work_item_id');

        return (clone $base)
            ->leftJoinSub($latest, 'latest_snapshot', function ($join) {
                $join->on('latest_snapshot.project_id', '=', 'time_entries.project_id')
                    ->on('latest_snapshot.devops_work_item_id', '=', 'time_entries.devops_work_item_id');
            })
            ->leftJoin('work_item_snapshots as snapshot', 'snapshot.id', '=', 'latest_snapshot.id')
            ->select([
                'time_entries.id',
                'time_entries.local_date',
                'time_entries.duration_seconds',
                DB::raw(self::BILLABLE.' as billable'),
                'time_entries.source',
                'time_entries.note',
                'time_entries.revision',
                'time_entries.devops_work_item_id',
                'time_entries.project_id',
                'time_entries.member_id',
                'time_entries.activity_type_id',
                'time_entries.timezone',
                'time_entries.started_at_utc',
                'time_entries.ended_at_utc',
                'snapshot.title as work_item_title',
                'snapshot.work_item_type',
                'snapshot.iteration_path',
                'members.display_name as member_name',
                'projects.devops_project_name as project_name',
                'activity_types.name as activity_name',
                'activity_types.color as activity_color',
                DB::raw("coalesce(weekly_submissions.status, 'open') as week_status"),
            ])
            ->orderBy('time_entries.local_date')
            ->orderBy('members.display_name')
            ->orderBy('time_entries.id');
    }

    /**
     * @param  Builder<TimeEntry>  $base
     * @return list<array{id: string|null, name: string, totalSeconds: int, entryCount: int}>
     */
    private function grouped(Builder $base, string $idColumn, string $nameColumn, string $emptyName): array
    {
        return (clone $base)->toBase()
            ->selectRaw("{$idColumn} as group_id, {$nameColumn} as group_name, sum(time_entries.duration_seconds) as total_seconds, count(*) as entry_count")
            ->groupBy($idColumn, $nameColumn)
            ->orderByDesc('total_seconds')
            ->orderBy('group_name')
            ->get()
            ->map(fn ($row) => [
                'id' => $row->group_id === null ? null : (string) $row->group_id,
                'name' => $row->group_name ?? $emptyName,
                'totalSeconds' => (int) $row->total_seconds,
                'entryCount' => (int) $row->entry_count,
            ])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRow(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'localDate' => substr((string) $row->local_date, 0, 10),
            'memberId' => (string) $row->member_id,
            'memberName' => $row->member_name,
            'projectId' => (string) $row->project_id,
            'projectName' => $row->project_name,
            'workItemId' => (int) $row->devops_work_item_id,
            'workItemTitle' => $row->work_item_title,
            'workItemType' => $row->work_item_type,
            'iterationPath' => $row->iteration_path,
            'startTime' => $this->localTime($row->started_at_utc, $row->timezone),
            'endTime' => $this->localTime($row->ended_at_utc, $row->timezone),
            'activityTypeId' => $row->activity_type_id === null ? null : (string) $row->activity_type_id,
            'activityTypeName' => $row->activity_name,
            'activityTypeColor' => $row->activity_color,
            'durationSeconds' => (int) $row->duration_seconds,
            'billable' => (bool) $row->billable,
            'source' => $row->source,
            'note' => $row->note,
            'revision' => (int) $row->revision,
            'weekStatus' => $row->week_status ?? WeeklySubmission::STATUS_OPEN,
        ];
    }

    /** Hora local 'HH:MM' no fuso em que o lançamento foi feito; nulo se não houver horário. */
    private function localTime(mixed $utc, ?string $timezone): ?string
    {
        if ($utc === null || $utc === '') {
            return null;
        }

        return CarbonImmutable::parse((string) $utc, 'UTC')->setTimezone($timezone ?: 'UTC')->format('H:i');
    }

    /**
     * Projetos que o escopo enxerga: os gerenciados + aqueles em que a pessoa
     * tem lançamento próprio (ou todos, se for escopo total).
     *
     * @return list<int>
     */
    private function visibleProjectIds(Tenant $tenant, ReportScope $scope): array
    {
        if ($scope->tenantWide) {
            return Project::query()->where('tenant_id', $tenant->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        }

        $own = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $scope->memberId)
            ->distinct()
            ->pluck('project_id')
            ->map(fn ($id) => (int) $id);

        return $own->merge($scope->managedProjectIds)->unique()->values()->all();
    }
}
