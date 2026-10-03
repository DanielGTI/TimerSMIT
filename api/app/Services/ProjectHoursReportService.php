<?php

namespace App\Services;

use App\Models\Holiday;
use App\Models\HourBankMovement;
use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use Carbon\CarbonImmutable;

/**
 * Horas do mês por projeto e pessoa, no formato que vai para o
 * administrativo (centro de custo de cada projeto):
 *
 * - Base de jornada da pessoa = dias úteis (seg–sex) − feriados do calendário
 *   − folgas do banco de horas dela, vezes a jornada diária.
 * - TOTAL = horas nos projetos. Projetos marcados como "conta como hora
 *   ociosa" ficam com a linha zerada: as horas deles não são produtivas.
 * - Horas Ociosas = base − TOTAL (nunca negativo).
 *
 * Conta pelo dia do lançamento; `approvedOnly` deixa só semanas aprovadas.
 */
class ProjectHoursReportService
{
    /**
     * @param  string  $month  YYYY-MM
     * @return array<string, mixed>
     */
    public function month(Tenant $tenant, string $month, float $dailyHours, bool $approvedOnly): array
    {
        $first = CarbonImmutable::parse("{$month}-01");
        $start = $first->toDateString();
        $end = $first->endOfMonth()->toDateString();
        $dailySeconds = (int) round($dailyHours * 3600);

        $entries = TimeEntry::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('local_date', [$start, $end])
            ->when($approvedOnly, fn ($query) => $query->whereExists(fn ($sub) => $sub->selectRaw('1')
                ->from('weekly_submissions')
                ->where('weekly_submissions.tenant_id', $tenant->id)
                ->whereColumn('weekly_submissions.member_id', 'time_entries.member_id')
                ->whereColumn('weekly_submissions.week_start_date', 'time_entries.week_start_date')
                ->where('weekly_submissions.status', WeeklySubmission::STATUS_APPROVED)))
            ->get(['id', 'project_id', 'member_id', 'duration_seconds']);

        $idleProjectIds = Project::query()->where('tenant_id', $tenant->id)->where('counts_as_idle', true)->pluck('id');

        $projects = Project::query()
            ->where('tenant_id', $tenant->id)
            ->where(fn ($query) => $query->whereIn('id', $entries->pluck('project_id')->unique()->values()->all())
                ->orWhere(fn ($idle) => $idle->where('counts_as_idle', true)->where('is_enabled', true)))
            ->orderBy('devops_project_name')
            ->get();

        $members = Member::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('id', $entries->pluck('member_id')->unique()->values()->all())
            ->orderBy('display_name')
            ->get();

        // Calendário do mês: dias úteis e feriados que caem neles.
        $holidays = Holiday::query()
            ->where('tenant_id', $tenant->id)
            ->whereBetween('date', [$start, $end])
            ->orderBy('date')
            ->get()
            ->filter(fn (Holiday $holiday) => CarbonImmutable::parse($holiday->date)->isWeekday())
            ->values();

        $weekdays = 0;
        for ($day = $first; $day->toDateString() <= $end; $day = $day->addDay()) {
            $weekdays += $day->isWeekday() ? 1 : 0;
        }
        $holidayDates = $holidays->map(fn (Holiday $holiday) => substr((string) $holiday->date, 0, 10))->flip();
        $calendarSeconds = ($weekdays - $holidays->count()) * $dailySeconds;

        // Folgas do banco de horas em dia útil reduzem a base da pessoa.
        $timeOff = HourBankMovement::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', HourBankMovement::KIND_TIME_OFF)
            ->whereBetween('local_date', [$start, $end])
            ->whereIn('member_id', $members->pluck('id')->all())
            ->orderBy('local_date')
            ->get()
            ->filter(fn (HourBankMovement $movement) => $movement->local_date->isWeekday()
                && ! $holidayDates->has($movement->local_date->toDateString()));

        $cells = [];
        foreach ($entries as $entry) {
            $cells[$entry->project_id][$entry->member_id] = ($cells[$entry->project_id][$entry->member_id] ?? 0) + $entry->duration_seconds;
        }

        $people = [];
        foreach ($members as $member) {
            $off = $timeOff->where('member_id', $member->id);
            $base = max(0, $calendarSeconds - (int) $off->sum(fn (HourBankMovement $movement) => abs($movement->seconds)));
            $productive = 0;
            $idleProjects = 0;

            foreach ($projects as $project) {
                $seconds = $cells[$project->id][$member->id] ?? 0;
                if ($idleProjectIds->contains($project->id)) {
                    $idleProjects += $seconds;
                } else {
                    $productive += $seconds;
                }
            }

            $people[] = [
                'memberId' => (string) $member->id,
                'name' => $member->display_name,
                'baseSeconds' => $base,
                'timeOff' => $off->map(fn (HourBankMovement $movement) => [
                    'date' => $movement->local_date->toDateString(),
                    'seconds' => abs($movement->seconds),
                ])->values()->all(),
                'totalSeconds' => $productive,
                'idleProjectSeconds' => $idleProjects,
                'idleSeconds' => max(0, $base - $productive),
            ];
        }

        $rows = $projects->map(function (Project $project) use ($cells, $members, $idleProjectIds) {
            $idle = $idleProjectIds->contains($project->id);
            $hours = [];
            foreach ($members as $member) {
                $hours[(string) $member->id] = $idle ? 0 : ($cells[$project->id][$member->id] ?? 0);
            }

            return [
                'projectId' => (string) $project->id,
                'name' => $project->devops_project_name,
                'countsAsIdle' => $idle,
                'seconds' => $hours,
                'totalSeconds' => array_sum($hours),
            ];
        })->values()->all();

        return [
            'month' => $month,
            'from' => $start,
            'to' => $end,
            'dailyHours' => $dailyHours,
            'approvedOnly' => $approvedOnly,
            'weekdays' => $weekdays,
            'holidays' => $holidays->map(fn (Holiday $holiday) => ['date' => substr((string) $holiday->date, 0, 10), 'name' => $holiday->name])->all(),
            'calendarBaseSeconds' => $calendarSeconds,
            'idleProjects' => $projects->filter(fn (Project $project) => $idleProjectIds->contains($project->id))->pluck('devops_project_name')->values()->all(),
            'people' => $people,
            'projects' => $rows,
            'totals' => [
                'totalSeconds' => array_sum(array_column($people, 'totalSeconds')),
                'idleSeconds' => array_sum(array_column($people, 'idleSeconds')),
            ],
            'note' => $this->note($weekdays, $holidays->all(), $calendarSeconds, $dailySeconds, $people, $projects->filter(fn (Project $project) => $idleProjectIds->contains($project->id))->pluck('devops_project_name')->values()->all()),
        ];
    }

    /**
     * "Base de jornada: 168h (23 dias úteis; 09/07 feriado; 10/07 banco de horas). Horas Ociosas incluem Laravel-Inspinia."
     *
     * @param  list<Holiday>  $holidays
     * @param  list<array<string, mixed>>  $people
     * @param  list<string>  $idleProjects
     */
    private function note(int $weekdays, array $holidays, int $calendarSeconds, int $dailySeconds, array $people, array $idleProjects): string
    {
        $parts = ["{$weekdays} dias úteis"];
        foreach ($holidays as $holiday) {
            $parts[] = CarbonImmutable::parse($holiday->date)->format('d/m').' feriado';
        }

        // Folga coletiva (a mesma para todos) entra na base comum; a individual vai por pessoa.
        $offSets = array_map(fn (array $person) => json_encode($person['timeOff']), $people);
        $shared = $people !== [] && count(array_unique($offSets)) === 1;

        if ($shared) {
            foreach ($people[0]['timeOff'] as $off) {
                $parts[] = CarbonImmutable::parse($off['date'])->format('d/m').' banco de horas'.($off['seconds'] !== $dailySeconds ? ' ('.self::hours($off['seconds']).')' : '');
            }
            $base = $people[0]['baseSeconds'];
        } else {
            $base = $calendarSeconds;
        }

        $note = 'Base de jornada: '.self::hours($base).' ('.implode('; ', $parts).').';

        if (! $shared) {
            $individual = array_filter($people, fn (array $person) => $person['timeOff'] !== []);
            if ($individual !== []) {
                $note .= ' Menos folgas do banco de horas: '.implode('; ', array_map(
                    fn (array $person) => $person['name'].' '.implode(', ', array_map(fn (array $off) => CarbonImmutable::parse($off['date'])->format('d/m'), $person['timeOff'])).' (base '.self::hours($person['baseSeconds']).')',
                    $individual,
                )).'.';
            }
        }

        if ($idleProjects !== []) {
            $note .= ' Horas Ociosas incluem '.implode(', ', $idleProjects).'.';
        }

        return $note;
    }

    /** 604800 → "168h"; 5400 → "1h30". */
    private static function hours(int $seconds): string
    {
        $minutes = (int) round($seconds / 60);
        $rest = $minutes % 60;

        return intdiv($minutes, 60).'h'.($rest > 0 ? sprintf('%02d', $rest) : '');
    }
}
