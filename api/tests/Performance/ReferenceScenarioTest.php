<?php

namespace Tests\Performance;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use App\Support\WeekCalendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * T040 / SC-004 — cenário de referência: a pessoa medida tem 500 lançamentos
 * e a organização tem outras 100 pessoas com 500 cada (50 mil linhas de
 * ruído), então os índices por tenant/membro/data é que sustentam o tempo.
 * Meta: p95 de abrir a semana < 3 s e de iniciar/parar o timer < 2 s.
 *
 * Só roda com PERF_RUN=1 e, para valer, contra PostgreSQL (no SQLite os
 * planos e o tempo não dizem nada sobre a produção):
 *
 *   docker compose -f docker-compose.test.yml run --rm -e PERF_RUN=1 tests \
 *       php artisan test --filter=ReferenceScenarioTest
 *
 * Os números saem no console; o que está em specs/.../tasks.md veio de lá.
 */
class ReferenceScenarioTest extends TestCase
{
    use RefreshDatabase;

    private const NOISE_MEMBERS = 100;

    private const ENTRIES_PER_MEMBER = 500;

    private const ROUNDS = 40;

    protected function setUp(): void
    {
        parent::setUp();

        if (getenv('PERF_RUN') !== '1') {
            $this->markTestSkipped('Teste de carga: defina PERF_RUN=1 (e use PostgreSQL).');
        }
    }

    public function test_reference_scenario_meets_sc004(): void
    {
        [$tenant, $project, $member, $admin] = $this->seedReferenceScenario();

        $headers = fn (Member $who): array => [
            'Authorization' => 'Bearer '.app(SessionTokenService::class)->issue($tenant->id, $who->id)->token,
        ];

        $thisMonday = WeekCalendar::startOf(now()->toDateString());

        $week = $this->measure(self::ROUNDS, function (int $i) use ($headers, $member, $thisMonday) {
            $monday = Carbon::parse($thisMonday)->subWeeks($i % 12)->toDateString();
            $this->getJson("/api/me/weeks/{$monday}", $headers($member))->assertOk();
        });

        $month = $this->measure(self::ROUNDS, function () use ($headers, $member) {
            $this->getJson('/api/me/months/'.now()->format('Y-m'), $headers($member))->assertOk();
        });

        $start = [];
        $stop = [];
        for ($i = 0; $i < self::ROUNDS; $i++) {
            $started = null;
            $start[] = $this->time(function () use (&$started, $headers, $member, $project, $i) {
                $started = $this->postJson('/api/me/timer', [
                    'projectId' => $project->devops_project_id,
                    'projectName' => $project->devops_project_name,
                    'workItemId' => 1000 + $i,
                ], $headers($member) + ['Idempotency-Key' => sprintf('perf-start-%010d', $i)])->assertCreated();
            });

            Carbon::setTestNow(now()->addMinutes(2));
            $stop[] = $this->time(function () use ($started, $headers, $member, $i) {
                $this->postJson('/api/me/timer/stop', [
                    'timerId' => $started->json('id'),
                ], $headers($member) + ['Idempotency-Key' => sprintf('perf-stop-%011d', $i)])->assertOk();
            });
        }
        Carbon::setTestNow();

        $from = now()->subDays(90)->toDateString();
        $to = now()->toDateString();
        $report = $this->measure(10, function () use ($headers, $admin, $from, $to) {
            $this->getJson("/api/reports/time?from={$from}&to={$to}", $headers($admin))->assertOk();
        });
        $csv = $this->measure(5, function () use ($headers, $admin, $from, $to) {
            $this->get("/api/reports/time.csv?from={$from}&to={$to}", $headers($admin))->assertOk()->streamedContent();
        });

        $rows = DB::table('time_entries')->count();
        fwrite(STDERR, sprintf(
            "\n[T040] %s, %d lançamentos no total (%d da pessoa medida)\n",
            DB::connection()->getDriverName(),
            $rows,
            self::ENTRIES_PER_MEMBER,
        ));
        foreach ([
            'abrir semana' => $week, 'abrir mês' => $month, 'iniciar timer' => $start, 'parar timer' => $stop,
            'relatório 90 dias (admin)' => $report, 'CSV 90 dias (admin)' => $csv,
        ] as $name => $samples) {
            fwrite(STDERR, sprintf("[T040] %-28s p50=%6.0f ms  p95=%6.0f ms  máx=%6.0f ms\n", $name, $this->pct($samples, 50), $this->pct($samples, 95), max($samples)));
        }

        $this->assertLessThan(3000, $this->pct($week, 95), 'SC-004: p95 de abrir a semana deve ser < 3 s');
        $this->assertLessThan(2000, $this->pct($start, 95), 'SC-004: p95 de iniciar o timer deve ser < 2 s');
        $this->assertLessThan(2000, $this->pct($stop, 95), 'SC-004: p95 de parar o timer deve ser < 2 s');
        // Sem meta formal; o teto só pega regressão patológica (varredura, N+1).
        $this->assertLessThan(10000, $this->pct($report, 95), 'relatório de 90 dias deve ficar < 10 s');
    }

    /** @return array{0: Tenant, 1: Project, 2: Member, 3: Member} */
    private function seedReferenceScenario(): array
    {
        $tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();
        $admin = Member::factory()->for($tenant)->create();
        $noise = Member::factory()->for($tenant)->count(self::NOISE_MEMBERS)->create();

        foreach ([$member, $admin, ...$noise] as $who) {
            RoleAssignment::factory()->create([
                'tenant_id' => $tenant->id, 'member_id' => $who->id,
                'project_id' => $project->id, 'role' => RoleAssignment::ROLE_MEMBER,
            ]);
        }
        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id, 'member_id' => $admin->id,
            'project_id' => null, 'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        $now = now()->toDateTimeString();
        $buffer = [];
        foreach ([$member, ...$noise] as $who) {
            for ($i = 0; $i < self::ENTRIES_PER_MEMBER; $i++) {
                $date = now()->subDays($i % 84)->toDateString();
                $buffer[] = [
                    'tenant_id' => $tenant->id, 'project_id' => $project->id, 'member_id' => $who->id,
                    'devops_work_item_id' => 1 + ($i % 200), 'local_date' => $date,
                    'week_start_date' => WeekCalendar::startOf($date), 'timezone' => 'America/Sao_Paulo',
                    'duration_seconds' => 900 + ($i % 8) * 900, 'source' => 'manual', 'billable' => $i % 3 !== 0,
                    'note' => 'lançamento de carga '.$i, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
                ];
                if (count($buffer) === 1000) {
                    DB::table('time_entries')->insert($buffer);
                    $buffer = [];
                }
            }
        }
        if ($buffer !== []) {
            DB::table('time_entries')->insert($buffer);
        }

        // Estatísticas frescas, como o autovacuum deixaria em produção.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ANALYZE time_entries');
        }

        return [$tenant, $project, $member, $admin];
    }

    /** @return list<float> milissegundos */
    private function measure(int $rounds, callable $action): array
    {
        $samples = [];
        for ($i = 0; $i < $rounds; $i++) {
            $samples[] = $this->time(fn () => $action($i));
        }

        return $samples;
    }

    private function time(callable $action): float
    {
        $start = hrtime(true);
        $action();

        return (hrtime(true) - $start) / 1e6;
    }

    /** @param list<float> $samples */
    private function pct(array $samples, int $p): float
    {
        sort($samples);

        return $samples[(int) max(0, ceil(count($samples) * $p / 100) - 1)];
    }
}
