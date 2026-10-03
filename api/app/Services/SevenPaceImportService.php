<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Support\WeekCalendar;
use App\Support\XlsxReader;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Importa o relatório "Times Explorer" do 7pace Timetracker (.xlsx).
 *
 * Colunas esperadas (pelo título, em qualquer ordem): Hours, Person, Work
 * Item, Title, Date, Start, End, Team Project, Activity Type, Work Item Type,
 * Iteration Path, Comment. Datas e horas aceitam o número serial do Excel ou
 * texto (dd/mm/aaaa, aaaa-mm-dd, HH:MM, "1,5", "1:30").
 *
 * - Pessoas: pelo nome (sem acento/maiúscula); se não bater, pelo começo do
 *   nome ("Jhones" → "Jhones Michael Santana Vieira") quando só uma bate; ou
 *   pelo mapeamento escolhido na tela.
 * - Projetos: pelo nome; os que ainda não existem são criados com o GUID que
 *   a extensão descobre no Azure DevOps (`projectIds`).
 * - Atividades: pelo nome; "[Not Set]" fica sem atividade; nome novo é criado.
 * - Nada é duplicado: cada linha tem uma referência única (import_ref); a
 *   linha que bate com um lançamento já existente da pessoa (mesmo horário,
 *   ou mesmo dia + work item + duração) é pulada; semana enviada ou aprovada
 *   não recebe lançamento.
 * - Os lançamentos ficam com a data de criação no próprio dia trabalhado: as
 *   regras de hora adicional (que valem daqui para a frente) não os afetam.
 */
class SevenPaceImportService
{
    private const HEADERS = [
        'hours' => 'hours',
        'person' => 'person',
        'work item' => 'workItem',
        'title' => 'title',
        'date' => 'date',
        'start' => 'start',
        'end' => 'end',
        'team project' => 'project',
        'activity type' => 'activity',
        'work item type' => 'workItemType',
        'iteration path' => 'iterationPath',
        'comment' => 'comment',
    ];

    private const REQUIRED = ['hours', 'person', 'workItem', 'date', 'project'];

    private const NO_ACTIVITY = ['', '[not set]', 'not set'];

    private const NEW_ACTIVITY_COLOR = '#BDBDBD';

    public function __construct(
        private readonly ActivityTypeService $activityTypes,
        private readonly AuditService $audit,
    ) {}

    /**
     * Simula (dryRun) ou importa. Devolve o resumo nos dois casos.
     *
     * @param  array<string, string>  $personMap  nome na planilha → id do membro ("none" = deixar de fora)
     * @param  array<string, string>  $projectIds  nome do projeto → GUID no Azure DevOps
     * @return array<string, mixed>
     */
    public function run(Tenant $tenant, Member $actor, string $path, array $personMap, array $projectIds, bool $dryRun): array
    {
        ['rows' => $rows, 'errors' => $errors] = $this->parse($path);
        $timezone = $tenant->default_timezone ?: 'America/Sao_Paulo';

        $members = Member::query()->where('tenant_id', $tenant->id)->get();
        $people = $this->resolvePeople($rows, $members, $personMap);
        $projects = $this->resolveProjects($tenant, $rows, $projectIds);

        $this->activityTypes->ensureDefaults($tenant);
        $activities = ActivityType::query()->where('tenant_id', $tenant->id)->get()
            ->keyBy(fn (ActivityType $type) => self::key($type->name));

        // Referência única de cada linha: conteúdo + ordem entre linhas idênticas.
        $seen = [];
        foreach ($rows as &$row) {
            $hash = substr(sha1(implode('|', [$row['person'], $row['workItem'], $row['project'], $row['date'], $row['start'], $row['end'], $row['seconds']])), 0, 32);
            $seen[$hash] = ($seen[$hash] ?? 0) + 1;
            $row['ref'] = "7pace:{$hash}:{$seen[$hash]}";
        }
        unset($row);

        $plan = $this->plan($tenant, $rows, $people, $projects, $timezone);

        $summary = [
            'dryRun' => $dryRun,
            'rows' => count($rows),
            'totalSeconds' => array_sum(array_column($rows, 'seconds')),
            'from' => $rows === [] ? null : min(array_column($rows, 'date')),
            'to' => $rows === [] ? null : max(array_column($rows, 'date')),
            'people' => array_values($people),
            'projects' => array_values(array_map(fn (array $project) => array_diff_key($project, ['model' => true]), $projects)),
            'activities' => $this->activitySummary($rows, $activities),
            'toImport' => count($plan['import']),
            'toImportSeconds' => array_sum(array_map(fn (array $row) => $row['seconds'], $plan['import'])),
            'skipped' => $plan['skipped'],
            'warnings' => [...$errors, ...$plan['warnings']],
            'imported' => 0,
        ];

        if ($dryRun || $plan['import'] === []) {
            return $summary;
        }

        DB::transaction(function () use ($tenant, $actor, $plan, $people, $projects, $activities, $timezone, &$summary) {
            $projectModels = [];
            foreach ($projects as $name => $project) {
                $projectModels[$name] = $project['model'] ?? null;
                if ($projectModels[$name] === null && $project['devopsProjectId'] !== null) {
                    $projectModels[$name] = Project::query()->firstOrCreate(
                        ['tenant_id' => $tenant->id, 'devops_project_id' => $project['devopsProjectId']],
                        ['devops_project_name' => $name, 'is_enabled' => true],
                    );
                }
            }

            foreach ($plan['import'] as $row) {
                $activity = $this->activityFor($tenant, $activities, $row['activity']);
                $project = $projectModels[$row['project']];

                $this->snapshot($tenant, $project, $row);

                $entry = new TimeEntry([
                    'tenant_id' => $tenant->id,
                    'project_id' => $project->id,
                    'member_id' => (int) $people[$row['person']]['memberId'],
                    'devops_work_item_id' => $row['workItem'],
                    'activity_type_id' => $activity?->id,
                    'local_date' => $row['date'],
                    'timezone' => $timezone,
                    'duration_seconds' => $row['seconds'],
                    'started_at_utc' => $row['startUtc'],
                    'ended_at_utc' => $row['endUtc'],
                    'source' => TimeEntry::SOURCE_MANUAL,
                    'billable' => false,
                    'note' => $row['comment'] === '' ? null : mb_substr($row['comment'], 0, 2000),
                    'revision' => 1,
                    'import_ref' => $row['ref'],
                ]);
                // Criado "no dia trabalhado": regras posteriores (horas adicionais) não se aplicam.
                $entry->created_at = $row['createdAt'];
                $entry->updated_at = $row['createdAt'];
                $entry->save();

                $summary['imported']++;
            }

            $this->audit->record($tenant, 'import.seven_pace', TimeEntry::class, null, $actor, null, [
                'rows' => $summary['rows'],
                'imported' => $summary['imported'],
                'seconds' => $summary['toImportSeconds'],
                'from' => $summary['from'],
                'to' => $summary['to'],
                'skipped' => $summary['skipped'],
            ]);
        });

        return $summary;
    }

    /**
     * @return array{rows: list<array<string, mixed>>, errors: list<string>}
     */
    private function parse(string $path): array
    {
        try {
            $lines = XlsxReader::rows($path);
        } catch (RuntimeException $exception) {
            throw ValidationException::withMessages(['file' => $exception->getMessage()]);
        }

        // A linha de títulos não é necessariamente a primeira (o 7pace põe um cabeçalho antes).
        $columns = null;
        $firstRow = 0;
        foreach ($lines as $index => $line) {
            $found = [];
            foreach ($line as $column => $value) {
                $name = self::HEADERS[strtolower(trim((string) $value))] ?? null;
                if ($name !== null) {
                    $found[$name] = $column;
                }
            }
            if (count(array_intersect(self::REQUIRED, array_keys($found))) === count(self::REQUIRED)) {
                $columns = $found;
                $firstRow = $index + 1;
                break;
            }
        }

        if ($columns === null) {
            throw ValidationException::withMessages(['file' => 'Não encontrei as colunas do relatório do 7pace (Hours, Person, Work Item, Date, Team Project).']);
        }

        $rows = [];
        $errors = [];
        foreach (array_slice($lines, $firstRow, null, true) as $index => $line) {
            $get = fn (string $field) => isset($columns[$field]) ? trim((string) ($line[$columns[$field]] ?? '')) : '';

            if ($get('hours') === '' && $get('person') === '' && $get('workItem') === '') {
                continue;
            }

            $date = self::date($get('date'));
            $start = self::moment($get('start'), $date);
            $end = self::moment($get('end'), $date);
            $hours = self::hours($get('hours'));
            $workItem = (int) preg_replace('/\D/', '', $get('workItem'));

            if ($date === null || $hours === null || $hours <= 0 || $workItem <= 0 || $get('person') === '' || $get('project') === '') {
                $errors[] = 'Linha '.($index + 1).': faltam horas, pessoa, work item, data ou projeto.';

                continue;
            }

            $seconds = (int) round($hours * 3600);
            // Início e fim no mesmo dia e coerentes: o horário vale e a duração sai dele.
            if ($start !== null && $end !== null && $end > $start && substr($start, 0, 10) === $date && substr($end, 0, 10) === $date) {
                $seconds = (int) round(CarbonImmutable::parse($end)->diffInSeconds(CarbonImmutable::parse($start), true));
            } else {
                $start = $end = null;
            }

            $rows[] = [
                'line' => $index + 1,
                'hours' => $hours,
                'seconds' => $seconds,
                'person' => $get('person'),
                'workItem' => $workItem,
                'title' => $get('title'),
                'date' => $date,
                'start' => $start,
                'end' => $end,
                'project' => $get('project'),
                'activity' => $get('activity'),
                'workItemType' => $get('workItemType'),
                'iterationPath' => $get('iterationPath'),
                'comment' => $get('comment'),
            ];
        }

        if ($rows === []) {
            throw ValidationException::withMessages(['file' => $errors[0] ?? 'A planilha não tem lançamentos.']);
        }

        return ['rows' => $rows, 'errors' => $errors];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<int, Member>  $members
     * @param  array<string, string>  $personMap
     * @return array<string, array<string, mixed>>
     */
    private function resolvePeople(array $rows, Collection $members, array $personMap): array
    {
        $people = [];
        foreach ($rows as $row) {
            $person = $people[$row['person']] ?? ['name' => $row['person'], 'rows' => 0, 'seconds' => 0];
            $person['rows']++;
            $person['seconds'] += $row['seconds'];
            $people[$row['person']] = $person;
        }

        foreach ($people as $name => &$person) {
            $member = null;
            $match = null;

            // "none": quem importa decidiu deixar as horas dessa pessoa de fora.
            if (($personMap[$name] ?? null) === 'none') {
                $person['memberId'] = null;
                $person['memberName'] = null;
                $person['match'] = 'ignored';

                continue;
            }

            if (isset($personMap[$name]) && $personMap[$name] !== '') {
                $member = $members->firstWhere('id', (int) $personMap[$name]);
                $match = $member ? 'manual' : null;
            }

            if ($member === null) {
                $member = $members->first(fn (Member $candidate) => self::key($candidate->display_name) === self::key($name));
                $match = $member ? 'exact' : null;
            }

            if ($member === null) {
                $prefix = $members->filter(fn (Member $candidate) => str_starts_with(self::key($candidate->display_name).' ', self::key($name).' '));
                if ($prefix->count() === 1) {
                    $member = $prefix->first();
                    $match = 'prefix';
                }
            }

            $person['memberId'] = $member ? (string) $member->id : null;
            $person['memberName'] = $member?->display_name;
            $person['match'] = $match;
        }
        unset($person);

        return $people;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, string>  $projectIds
     * @return array<string, array<string, mixed>>
     */
    private function resolveProjects(Tenant $tenant, array $rows, array $projectIds): array
    {
        $existing = Project::query()->where('tenant_id', $tenant->id)->get();

        $projects = [];
        foreach ($rows as $row) {
            $project = $projects[$row['project']] ?? ['name' => $row['project'], 'rows' => 0, 'seconds' => 0, 'sampleWorkItemId' => $row['workItem']];
            $project['rows']++;
            $project['seconds'] += $row['seconds'];
            $projects[$row['project']] = $project;
        }

        foreach ($projects as $name => &$project) {
            $guid = $projectIds[$name] ?? null;
            $model = $guid !== null
                ? $existing->firstWhere('devops_project_id', $guid)
                : null;
            $model ??= $existing->first(fn (Project $candidate) => self::key($candidate->devops_project_name) === self::key($name));

            $project['model'] = $model;
            $project['projectId'] = $model ? (string) $model->id : null;
            $project['devopsProjectId'] = $model?->devops_project_id ?? $guid;
            $project['status'] = $model ? 'existing' : ($guid !== null ? 'create' : 'missing');
        }
        unset($project);

        return $projects;
    }

    /**
     * Decide linha a linha o que entra e o que fica de fora (e por quê).
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, array<string, mixed>>  $people
     * @param  array<string, array<string, mixed>>  $projects
     * @return array{import: list<array<string, mixed>>, skipped: array<string, int>, warnings: list<string>}
     */
    private function plan(Tenant $tenant, array $rows, array $people, array $projects, string $timezone): array
    {
        $skipped = ['unknownPerson' => 0, 'unknownProject' => 0, 'alreadyImported' => 0, 'alreadyLogged' => 0, 'lockedWeek' => 0];
        $memberIds = array_values(array_filter(array_map(fn (array $person) => $person['memberId'] !== null ? (int) $person['memberId'] : null, $people)));

        $refs = TimeEntry::withTrashed()->where('tenant_id', $tenant->id)->whereNotNull('import_ref')->pluck('import_ref')->flip();

        $dates = array_column($rows, 'date');
        $existing = $dates === [] ? collect() : TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('member_id', $memberIds)
            ->whereBetween('local_date', [min($dates), max($dates)])
            ->get(['id', 'member_id', 'project_id', 'devops_work_item_id', 'local_date', 'duration_seconds', 'started_at_utc', 'ended_at_utc'])
            ->groupBy(fn (TimeEntry $entry) => $entry->member_id.':'.substr((string) $entry->local_date, 0, 10));

        $locked = WeeklySubmission::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('member_id', $memberIds)
            ->whereIn('status', WeeklySubmission::LOCKED_STATUSES)
            ->get(['member_id', 'week_start_date'])
            ->mapWithKeys(fn (WeeklySubmission $submission) => [$submission->member_id.':'.substr((string) $submission->week_start_date, 0, 10) => true]);

        $import = [];
        $lockedWeeks = [];
        foreach ($rows as $row) {
            $memberId = $people[$row['person']]['memberId'];
            if ($memberId === null) {
                $skipped['unknownPerson']++;

                continue;
            }
            if ($projects[$row['project']]['status'] === 'missing') {
                $skipped['unknownProject']++;

                continue;
            }
            if ($refs->has($row['ref'])) {
                $skipped['alreadyImported']++;

                continue;
            }

            $weekStart = WeekCalendar::startOf($row['date']);
            if ($locked->has($memberId.':'.$weekStart)) {
                $skipped['lockedWeek']++;
                $lockedWeeks[$row['person'].' — semana de '.CarbonImmutable::parse($weekStart)->format('d/m')] = true;

                continue;
            }

            $startUtc = $row['start'] !== null ? CarbonImmutable::parse($row['start'], $timezone)->utc() : null;
            $endUtc = $row['end'] !== null ? CarbonImmutable::parse($row['end'], $timezone)->utc() : null;

            if ($this->alreadyLogged($existing->get($memberId.':'.$row['date'], collect()), $row, $startUtc, $endUtc)) {
                $skipped['alreadyLogged']++;

                continue;
            }

            $import[] = $row + [
                'startUtc' => $startUtc,
                'endUtc' => $endUtc,
                'createdAt' => $endUtc ?? CarbonImmutable::parse($row['date'].' 18:00', $timezone)->utc(),
            ];
        }

        $warnings = [];
        if ($lockedWeeks !== []) {
            $warnings[] = 'Semanas já enviadas ou aprovadas não recebem lançamentos: '.implode('; ', array_keys($lockedWeeks)).'. Reabra-as e importe de novo se precisar.';
        }

        return ['import' => $import, 'skipped' => $skipped, 'warnings' => $warnings];
    }

    /**
     * Já lançado no TimerSMIT: horário que se sobrepõe, ou mesmo work item no
     * mesmo dia com a mesma duração.
     *
     * @param  Collection<int, TimeEntry>  $sameDay
     * @param  array<string, mixed>  $row
     */
    private function alreadyLogged(Collection $sameDay, array $row, ?CarbonImmutable $startUtc, ?CarbonImmutable $endUtc): bool
    {
        foreach ($sameDay as $entry) {
            if ($startUtc !== null && $entry->started_at_utc !== null && $entry->ended_at_utc !== null
                && $entry->started_at_utc->lt($endUtc) && $entry->ended_at_utc->gt($startUtc)) {
                return true;
            }

            if ($entry->devops_work_item_id === $row['workItem'] && abs($entry->duration_seconds - $row['seconds']) < 60) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  Collection<string, ActivityType>  $activities
     */
    private function activityFor(Tenant $tenant, Collection $activities, string $name): ?ActivityType
    {
        $key = self::key($name);
        if (in_array($key, self::NO_ACTIVITY, true)) {
            return null;
        }

        if (! $activities->has($key)) {
            $activities->put($key, ActivityType::query()->create([
                'tenant_id' => $tenant->id,
                'name' => mb_substr(trim($name), 0, 100),
                'color' => self::NEW_ACTIVITY_COLOR,
                'is_enabled' => true,
                'is_default_billable' => false,
            ]));
        }

        return $activities->get($key);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  Collection<string, ActivityType>  $activities
     * @return list<array<string, mixed>>
     */
    private function activitySummary(array $rows, Collection $activities): array
    {
        $summary = [];
        foreach ($rows as $row) {
            $key = self::key($row['activity']);
            $item = $summary[$key] ?? [
                'name' => $row['activity'] === '' ? '[Not Set]' : $row['activity'],
                'rows' => 0,
                'status' => in_array($key, self::NO_ACTIVITY, true) ? 'none' : ($activities->has($key) ? 'existing' : 'create'),
            ];
            $item['rows']++;
            $summary[$key] = $item;
        }

        return array_values($summary);
    }

    /** Título, tipo e iteração do work item, para os relatórios (se ainda não houver). */
    private function snapshot(Tenant $tenant, Project $project, array $row): void
    {
        if ($row['title'] === '') {
            return;
        }

        $exists = WorkItemSnapshot::query()
            ->where('tenant_id', $tenant->id)
            ->where('project_id', $project->id)
            ->where('devops_work_item_id', $row['workItem'])
            ->exists();

        if (! $exists) {
            WorkItemSnapshot::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'devops_work_item_id' => $row['workItem'],
                'title' => mb_substr($row['title'], 0, 255),
                'work_item_type' => mb_substr($row['workItemType'] !== '' ? $row['workItemType'] : 'Task', 0, 100),
                'iteration_path' => $row['iterationPath'] !== '' ? mb_substr($row['iterationPath'], 0, 500) : null,
                'captured_at' => $row['createdAt'],
            ]);
        }
    }

    /** Número serial do Excel, "dd/mm/aaaa" ou "aaaa-mm-dd" → "Y-m-d". */
    private static function date(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return substr(XlsxReader::serialToDateTime((float) $value), 0, 10);
        }
        if (preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})#', $value, $m)) {
            return sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        }
        if (preg_match('#^(\d{4})-(\d{2})-(\d{2})#', $value, $m)) {
            return "{$m[1]}-{$m[2]}-{$m[3]}";
        }

        return null;
    }

    /** Data e hora local "Y-m-d H:i:s": serial com data, só a fração (hora) ou "HH:MM". */
    private static function moment(string $value, ?string $date): ?string
    {
        if ($value === '' || $date === null) {
            return null;
        }
        if (is_numeric($value)) {
            $serial = (float) $value;

            return $serial >= 1
                ? XlsxReader::serialToDateTime($serial)
                : $date.' '.substr(XlsxReader::serialToDateTime(25569 + $serial), 11);
        }
        if (preg_match('/(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/', $value, $m)) {
            $day = self::date($value) ?? $date;

            return sprintf('%s %02d:%02d:%02d', $day, $m[1], $m[2], $m[3] ?? 0);
        }

        return null;
    }

    /** "1.5", "1,5" ou "1:30" → 1.5 horas. */
    private static function hours(string $value): ?float
    {
        if (preg_match('/^(\d+):(\d{2})(?::(\d{2}))?$/', $value, $m)) {
            return (int) $m[1] + (int) $m[2] / 60 + (int) ($m[3] ?? 0) / 3600;
        }
        $value = str_replace(',', '.', $value);

        return is_numeric($value) ? (float) $value : null;
    }

    /** Comparação de nomes: sem acento, minúsculas, espaços simples. */
    private static function key(string $name): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower(Str::ascii($name))));
    }
}
