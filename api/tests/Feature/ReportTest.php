<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T034 — relatórios (US4): filtros, estado da semana, escopo por papel/projeto,
 * igualdade tela × CSV e auditoria da exportação.
 *
 * Dados (período 2026-09-28 a 2026-10-04; projetos A e B):
 *   alice  A  09-28 3600 (faturável, Desenvolvimento, item 10)  · semana APROVADA
 *   alice  A  09-29 1800                                          · semana APROVADA
 *   bob    B  09-28 7200 (faturável)                              · semana ENVIADA
 *   carol  A  09-30 5400                                          · semana aberta
 *   gerente A 09-30  600 e B 10-01 900 (lançamentos próprios)     · semana aberta
 *   alice  A  08-01 4000 (fora do período) e um lançamento excluído (não contam)
 */
class ReportTest extends TestCase
{
    use RefreshDatabase;

    private const FROM = '2026-09-28';

    private const TO = '2026-10-04';

    private Tenant $tenant;

    private Project $a;

    private Project $b;

    private Project $c;

    private Member $admin;

    private Member $manager;

    private Member $alice;

    private Member $bob;

    private Member $carol;

    private ActivityType $dev;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create();
        $this->a = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Projeto A']);
        $this->b = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Projeto B']);
        $this->c = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Projeto C']);

        $this->admin = $this->member('Ana Admin');
        $this->manager = $this->member('Gil Gerente');
        $this->alice = $this->member('Alice');
        $this->bob = $this->member('Bob');
        $this->carol = $this->member('Carol');

        $this->role($this->admin, RoleAssignment::ROLE_ADMIN);
        $this->role($this->manager, RoleAssignment::ROLE_MANAGER, $this->a);

        $this->dev = ActivityType::factory()->for($this->tenant)->create(['name' => 'Desenvolvimento', 'color' => '#A6D8F5']);

        $this->entry($this->alice, $this->a, '2026-09-28', 3600, ['billable' => true, 'activity_type_id' => $this->dev->id, 'devops_work_item_id' => 10, 'note' => 'primeira']);
        $this->entry($this->alice, $this->a, '2026-09-29', 1800);
        $this->entry($this->bob, $this->b, '2026-09-28', 7200, ['billable' => true]);
        $this->entry($this->carol, $this->a, '2026-09-30', 5400);
        $this->entry($this->manager, $this->a, '2026-09-30', 600);
        $this->entry($this->manager, $this->b, '2026-10-01', 900);
        $this->entry($this->alice, $this->a, '2026-08-01', 4000);
        $this->entry($this->alice, $this->a, '2026-09-30', 999)->delete();

        WeeklySubmission::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->alice->id, 'week_start_date' => '2026-09-28', 'status' => WeeklySubmission::STATUS_APPROVED]);
        WeeklySubmission::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $this->bob->id, 'week_start_date' => '2026-09-28', 'status' => WeeklySubmission::STATUS_SUBMITTED]);

        $this->travelTo('2026-10-05 12:00:00');
    }

    private function member(string $name, ?Tenant $tenant = null): Member
    {
        return Member::factory()->for($tenant ?? $this->tenant)->create(['display_name' => $name]);
    }

    private function role(Member $member, string $role, ?Project $project = null): void
    {
        RoleAssignment::factory()->create(['tenant_id' => $member->tenant_id, 'member_id' => $member->id, 'project_id' => $project?->id, 'role' => $role]);
    }

    private function entry(Member $member, Project $project, string $date, int $seconds, array $overrides = []): TimeEntry
    {
        return TimeEntry::factory()->create(array_merge([
            'tenant_id' => $member->tenant_id,
            'project_id' => $project->id,
            'member_id' => $member->id,
            'local_date' => $date,
            'duration_seconds' => $seconds,
            'billable' => false,
            'note' => null,
        ], $overrides));
    }

    private function auth(Member $member): array
    {
        $session = app(SessionTokenService::class)->issue($member->tenant_id, $member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Accept' => '*/*'];
    }

    private function query(array $extra = []): string
    {
        return http_build_query(array_merge(['from' => self::FROM, 'to' => self::TO], $extra));
    }

    private function report(Member $as, array $extra = [])
    {
        return $this->getJson('/api/reports/time?'.$this->query($extra), $this->auth($as));
    }

    private function csv(Member $as, array $extra = [])
    {
        return $this->get('/api/reports/time.csv?'.$this->query($extra), $this->auth($as));
    }

    /** @return list<list<string>> linhas do CSV sem o cabeçalho */
    private function csvRows(string $content): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $lines = preg_split('/\r\n/', trim(substr($content, 3)));
        $rows = array_map(fn (string $line) => str_getcsv($line, ';', '"', ''), $lines);
        array_shift($rows);

        return $rows;
    }

    // ---------- totais, filtros, estado ----------

    public function test_admin_sees_the_whole_organization_with_consistent_totals(): void
    {
        $response = $this->report($this->admin)->assertOk();

        $response->assertJsonPath('scope.level', 'all')
            ->assertJsonPath('totals.totalSeconds', 19500)
            ->assertJsonPath('totals.billableSeconds', 10800)
            ->assertJsonPath('totals.nonBillableSeconds', 8700)
            ->assertJsonPath('totals.entryCount', 6);

        $rows = $response->json('rows');
        $this->assertCount(6, $rows);
        $this->assertSame(19500, array_sum(array_column($rows, 'durationSeconds')));
        foreach (['byMember', 'byProject', 'byActivity'] as $group) {
            $this->assertSame(19500, array_sum(array_column($response->json($group), 'totalSeconds')), $group);
            $this->assertSame(6, array_sum(array_column($response->json($group), 'entryCount')), $group);
        }
    }

    public function test_aggregates_are_named_and_ordered_by_hours(): void
    {
        $response = $this->report($this->admin)->assertOk();

        $byProject = $response->json('byProject');
        $this->assertSame(['Projeto A', 'Projeto B'], array_column($byProject, 'name'));
        $this->assertSame([11400 - 0, 8100], array_column($byProject, 'totalSeconds'));

        $byActivity = $response->json('byActivity');
        $this->assertSame('Desenvolvimento', collect($byActivity)->firstWhere('totalSeconds', 3600)['name']);
        $this->assertContains('Não definido', array_column($byActivity, 'name'));
    }

    public function test_period_is_inclusive_and_excludes_outside_days_and_deleted_entries(): void
    {
        $this->entry($this->alice, $this->a, '2026-10-04', 60);
        $this->entry($this->alice, $this->a, '2026-09-27', 60);
        $this->entry($this->alice, $this->a, '2026-10-05', 60);

        $dates = array_column($this->report($this->admin)->json('rows'), 'localDate');

        $this->assertContains('2026-10-04', $dates);
        $this->assertNotContains('2026-09-27', $dates);
        $this->assertNotContains('2026-10-05', $dates);
        $this->assertNotContains('2026-08-01', $dates);
        $this->assertNotContains(999, array_column($this->report($this->admin)->json('rows'), 'durationSeconds'));
    }

    public function test_status_filter_uses_the_state_of_the_entrys_week(): void
    {
        $secondsFor = fn (string $status) => $this->report($this->admin, ['status' => $status])->assertOk()->json('totals.totalSeconds');

        $this->assertSame(5400, $secondsFor('approved'));   // alice: 3600 + 1800
        $this->assertSame(7200, $secondsFor('submitted'));  // bob
        $this->assertSame(6900, $secondsFor('open'));       // carol + gerente (semanas sem envio)
        $this->assertSame(0, $secondsFor('rejected'));

        $this->assertSame(
            ['approved'],
            array_unique(array_column($this->report($this->admin, ['status' => 'approved'])->json('rows'), 'weekStatus')),
        );
    }

    public function test_each_row_carries_its_week_status(): void
    {
        $byMember = collect($this->report($this->admin)->json('rows'))->groupBy('memberName')->map(fn ($rows) => $rows->pluck('weekStatus')->unique()->all());

        $this->assertSame(['approved'], $byMember['Alice']);
        $this->assertSame(['submitted'], $byMember['Bob']);
        $this->assertSame(['open'], $byMember['Carol']);
    }

    public function test_every_filter_narrows_the_result(): void
    {
        $seconds = fn (array $extra) => $this->report($this->admin, $extra)->assertOk()->json('totals.totalSeconds');

        $this->assertSame(11400, $seconds(['projectId' => $this->a->id]));
        $this->assertSame(8100, $seconds(['projectId' => $this->b->id]));
        $this->assertSame(5400, $seconds(['memberId' => $this->alice->id]));
        $this->assertSame(3600, $seconds(['workItemId' => 10]));
        $this->assertSame(3600, $seconds(['activityTypeId' => $this->dev->id]));
        $this->assertSame(10800, $seconds(['billable' => 'true']));
        $this->assertSame(8700, $seconds(['billable' => 'false']));
        $this->assertSame(3600, $seconds(['memberId' => $this->alice->id, 'projectId' => $this->a->id, 'billable' => '1']));
        $this->assertSame(0, $seconds(['memberId' => $this->bob->id, 'projectId' => $this->a->id]));
    }

    public function test_rows_show_display_details_and_the_latest_work_item_title(): void
    {
        foreach ([['Antigo', now()->subDay()], ['Título atual', now()]] as [$title, $at]) {
            WorkItemSnapshot::query()->create(['tenant_id' => $this->tenant->id, 'project_id' => $this->a->id, 'devops_work_item_id' => 10, 'title' => $title, 'work_item_type' => 'Task', 'captured_at' => $at]);
        }

        $row = collect($this->report($this->admin, ['workItemId' => 10])->json('rows'))->first();

        $this->assertSame('Título atual', $row['workItemTitle']);
        $this->assertSame('Desenvolvimento', $row['activityTypeName']);
        $this->assertSame('#A6D8F5', $row['activityTypeColor']);
        $this->assertSame('Projeto A', $row['projectName']);
        $this->assertSame('Alice', $row['memberName']);
        $this->assertTrue($row['billable']);
        $this->assertSame('primeira', $row['note']);
    }

    public function test_pagination_walks_every_row_exactly_once_while_totals_cover_the_whole_filter(): void
    {
        $seen = [];
        foreach ([1, 2, 3] as $page) {
            $response = $this->report($this->admin, ['perPage' => 2, 'page' => $page])->assertOk();
            $response->assertJsonPath('pagination.total', 6)->assertJsonPath('pagination.lastPage', 3)->assertJsonPath('totals.totalSeconds', 19500);
            $seen = array_merge($seen, array_column($response->json('rows'), 'id'));
        }

        $this->assertCount(6, $seen);
        $this->assertCount(6, array_unique($seen));
        $this->assertSame([], $this->report($this->admin, ['perPage' => 2, 'page' => 4])->json('rows'));
    }

    // ---------- escopo ----------

    public function test_manager_sees_everyone_in_their_project_plus_their_own_hours_elsewhere(): void
    {
        $response = $this->report($this->manager)->assertOk();

        $response->assertJsonPath('scope.level', 'projects')
            ->assertJsonPath('totals.totalSeconds', 12300) // A: 3600+1800+5400+600 · B (próprio): 900
            ->assertJsonPath('totals.entryCount', 5);
        $this->assertNotContains('Bob', array_column($response->json('rows'), 'memberName'));
        $this->assertNotContains('Bob', array_column($response->json('byMember'), 'name'));
    }

    public function test_changing_url_params_never_reveals_other_projects_to_a_manager(): void
    {
        // Projeto B é "visível" (há lançamento próprio) mas só as horas próprias aparecem.
        $this->report($this->manager, ['projectId' => $this->b->id])->assertOk()
            ->assertJsonPath('totals.totalSeconds', 900);

        // Pedir a pessoa de outro projeto não traz nada dela fora do escopo.
        $this->report($this->manager, ['memberId' => $this->bob->id])->assertOk()
            ->assertJsonPath('totals.totalSeconds', 0)
            ->assertJsonPath('totals.entryCount', 0);

        // Projeto sem relação com o gestor, ou de outra organização: 403.
        $this->report($this->manager, ['projectId' => $this->c->id])->assertStatus(403);
        $foreign = Project::factory()->for(Tenant::factory()->create())->create();
        $this->report($this->manager, ['projectId' => $foreign->id])->assertStatus(403);
        $this->report($this->admin, ['projectId' => $foreign->id])->assertStatus(403);
    }

    public function test_a_plain_member_only_sees_their_own_hours(): void
    {
        $this->role($this->alice, RoleAssignment::ROLE_MEMBER, $this->a);

        $this->report($this->alice)->assertOk()
            ->assertJsonPath('scope.level', 'self')
            ->assertJsonPath('scope.canFilterByMember', false)
            ->assertJsonPath('totals.totalSeconds', 5400);

        $this->report($this->alice, ['memberId' => $this->bob->id])->assertStatus(403);
        $this->report($this->alice, ['projectId' => $this->b->id])->assertStatus(403);
        $this->report($this->alice, ['memberId' => $this->alice->id])->assertOk()->assertJsonPath('totals.totalSeconds', 5400);
    }

    public function test_other_organizations_never_leak_into_a_report(): void
    {
        $other = Tenant::factory()->create();
        $foreignAdmin = $this->member('Admin de fora', $other);
        $this->role($foreignAdmin, RoleAssignment::ROLE_ADMIN);
        $foreignProject = Project::factory()->for($other)->create();
        $this->entry($foreignAdmin, $foreignProject, '2026-09-29', 111);

        $this->report($foreignAdmin)->assertOk()->assertJsonPath('totals.totalSeconds', 111);
        $this->assertNotContains(111, array_column($this->report($this->admin)->json('rows'), 'durationSeconds'));
    }

    public function test_options_list_only_what_the_scope_allows(): void
    {
        $admin = $this->getJson('/api/reports/options', $this->auth($this->admin))->assertOk();
        $this->assertCount(5, $admin->json('members'));
        $this->assertSame(['Projeto A', 'Projeto B', 'Projeto C'], array_column($admin->json('projects'), 'name'));

        $manager = $this->getJson('/api/reports/options', $this->auth($this->manager))->assertOk();
        $this->assertEqualsCanonicalizing(['Alice', 'Carol', 'Gil Gerente'], array_column($manager->json('members'), 'name'));
        $this->assertSame(['Projeto A', 'Projeto B'], array_column($manager->json('projects'), 'name'));

        $alice = $this->getJson('/api/reports/options', $this->auth($this->alice))->assertOk();
        $this->assertSame(['Alice'], array_column($alice->json('members'), 'name'));
        $this->assertSame(['Projeto A'], array_column($alice->json('projects'), 'name'));
        $this->assertSame('Desenvolvimento', $alice->json('activityTypes.0.name'));
    }

    // ---------- validação ----------

    public function test_invalid_filters_are_rejected(): void
    {
        $as = $this->auth($this->admin);
        $get = fn (string $query) => $this->getJson('/api/reports/time?'.$query, $as);

        $get('to=2026-10-04')->assertStatus(422);
        $get('from=2026-10-04')->assertStatus(422);
        $get('from=2026-10-04&to=2026-09-28')->assertStatus(422);
        $get('from=2025-01-01&to=2026-10-04')->assertStatus(422);
        $get('from=2026-09-28&to=2026-10-04&status=pendente')->assertStatus(422);
        $get('from=2026-09-28&to=2026-10-04&perPage=500')->assertStatus(422);
        $get('from=28/09/2026&to=2026-10-04')->assertStatus(422);

        $foreignType = ActivityType::factory()->for(Tenant::factory()->create())->create();
        $get('from=2026-09-28&to=2026-10-04&activityTypeId='.$foreignType->id)->assertStatus(422);
    }

    public function test_reports_require_a_session(): void
    {
        $this->getJson('/api/reports/time?'.$this->query())->assertStatus(401);
        $this->getJson('/api/reports/time.csv?'.$this->query())->assertStatus(401);
        $this->getJson('/api/reports/options')->assertStatus(401);
    }

    // ---------- CSV ----------

    public function test_csv_has_the_same_rows_and_totals_as_the_screen_for_the_same_filters(): void
    {
        foreach ([[], ['status' => 'open'], ['projectId' => $this->a->id], ['billable' => 'true'], ['memberId' => $this->alice->id]] as $extra) {
            $screen = [];
            for ($page = 1; $page <= 10; $page++) {
                $rows = $this->report($this->admin, $extra + ['perPage' => 2, 'page' => $page])->json('rows');
                if ($rows === []) {
                    break;
                }
                $screen = array_merge($screen, $rows);
            }
            $totals = $this->report($this->admin, $extra)->json('totals');

            $csv = $this->csvRows($this->csv($this->admin, $extra)->assertOk()->streamedContent());

            $this->assertCount(count($screen), $csv, json_encode($extra));
            $this->assertSame($totals['entryCount'], count($csv));
            // Mesma ordem, mesmos valores (segundos exatos, coluna 9 do CSV).
            $this->assertSame(array_column($screen, 'durationSeconds'), array_map(fn ($row) => (int) $row[8], $csv), json_encode($extra));
            $this->assertSame($totals['totalSeconds'], array_sum(array_map(fn ($row) => (int) $row[8], $csv)));
            $this->assertSame(array_column($screen, 'localDate'), array_column($csv, 0));
            $this->assertSame(array_column($screen, 'memberName'), array_column($csv, 1));
        }
    }

    public function test_csv_spanning_several_read_blocks_loses_and_repeats_no_row(): void
    {
        // Mais que um bloco de leitura, com muitos empates de data e pessoa:
        // a paginação por chave não pode pular nem repetir linhas na fronteira.
        $now = now()->toDateTimeString();
        $rows = [];
        for ($i = 1; $i <= 2300; $i++) {
            $who = [$this->alice, $this->bob, $this->carol][$i % 3];
            $rows[] = [
                'tenant_id' => $this->tenant->id, 'project_id' => $this->a->id, 'member_id' => $who->id,
                'devops_work_item_id' => 1, 'local_date' => '2026-09-'.(28 + $i % 2), 'week_start_date' => '2026-09-28',
                'timezone' => 'America/Sao_Paulo', 'duration_seconds' => 100000 + $i, 'source' => 'manual',
                'billable' => false, 'revision' => 1, 'created_at' => $now, 'updated_at' => $now,
            ];
        }
        foreach (array_chunk($rows, 500) as $chunk) {
            \Illuminate\Support\Facades\DB::table('time_entries')->insert($chunk);
        }

        $csv = $this->csvRows($this->csv($this->admin)->assertOk()->streamedContent());
        $total = $this->report($this->admin)->json('totals.entryCount');

        $this->assertCount($total, $csv);
        $big = array_values(array_filter(array_map(fn ($row) => (int) $row[8], $csv), fn ($seconds) => $seconds > 100000));
        $this->assertCount(2300, array_unique($big));

        $keys = array_map(fn ($row) => $row[0].'|'.$row[1], $csv);
        $sorted = $keys;
        usort($sorted, fn ($a, $b) => strcmp($a, $b));
        $this->assertSame($sorted, $keys, 'a ordem data → pessoa deve ser a da tela');
    }

    public function test_csv_format_headers_and_content(): void
    {
        $response = $this->csv($this->admin, ['workItemId' => 10])->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));
        $this->assertStringContainsString('horas_2026-09-28_a_2026-10-04.csv', $response->headers->get('Content-Disposition'));

        $content = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBFData;Pessoa;Projeto;", $content);
        [$row] = $this->csvRows($content);
        $this->assertSame(['2026-09-28', 'Alice', 'Projeto A', '10'], array_slice($row, 0, 4));
        $this->assertSame(['Desenvolvimento', 'Sim', '01:00:00', '3600', 'Manual', 'Aprovada', 'primeira'], array_slice($row, 5));
    }

    public function test_csv_neutralizes_formulas_in_free_text(): void
    {
        $this->entry($this->carol, $this->a, '2026-10-02', 60, ['note' => '=HYPERLINK("http://mal.example";"clique")']);
        $this->entry($this->carol, $this->a, '2026-10-02', 61, ['note' => '@SOMA(A1)']);
        $this->entry($this->carol, $this->a, '2026-10-02', 62, ['note' => 'fim de linha; com separador e "aspas"']);

        $rows = collect($this->csvRows($this->csv($this->admin, ['memberId' => $this->carol->id])->streamedContent()))->keyBy(fn ($row) => $row[8]);

        $this->assertSame('\'=HYPERLINK("http://mal.example";"clique")', $rows['60'][11]);
        $this->assertSame("'@SOMA(A1)", $rows['61'][11]);
        $this->assertSame('fim de linha; com separador e "aspas"', $rows['62'][11]);
    }

    public function test_csv_export_is_audited_with_filters_and_counts(): void
    {
        $this->csv($this->manager, ['status' => 'open'])->assertOk()->streamedContent();

        $event = AuditEvent::query()->where('action', 'report.exported')->firstOrFail();
        $this->assertSame($this->manager->id, $event->actor_member_id);
        $this->assertSame($this->tenant->id, $event->tenant_id);
        $this->assertSame(3, $event->context['rowCount']);          // carol 5400 + gerente 600 + 900
        $this->assertSame(6900, $event->context['totalSeconds']);
        $this->assertSame('projects', $event->context['scope']);
        $this->assertSame('open', $event->context['filters']['status']);
    }

    public function test_csv_follows_the_same_scope_and_refused_exports_are_not_audited(): void
    {
        $manager = $this->csvRows($this->csv($this->manager)->streamedContent());
        $this->assertNotContains('Bob', array_column($manager, 1));
        $this->assertCount(5, $manager);

        $this->csv($this->manager, ['projectId' => $this->c->id])->assertStatus(403);
        $this->csv($this->alice, ['memberId' => $this->bob->id])->assertStatus(403);
        // Só a exportação permitida (a primeira) foi auditada; as recusadas não.
        $this->assertSame(1, AuditEvent::query()->where('action', 'report.exported')->count());
    }

    // ---------- week_start_date ----------

    public function test_new_entries_always_get_their_week_start_date(): void
    {
        $this->assertSame('2026-09-28', TimeEntry::query()->where('duration_seconds', 3600)->firstOrFail()->week_start_date);
        $this->assertSame('2026-09-28', $this->entry($this->carol, $this->a, '2026-10-04', 60)->week_start_date);
        $this->assertSame('2026-10-05', $this->entry($this->carol, $this->a, '2026-10-05', 60)->week_start_date);
    }

    public function test_backfill_fills_legacy_rows_including_datetime_shaped_dates(): void
    {
        $legacy = $this->entry($this->carol, $this->a, '2026-09-30', 60);
        $datetimeShaped = $this->entry($this->carol, $this->a, '2026-10-01', 60);
        \DB::table('time_entries')->whereIn('id', [$legacy->id, $datetimeShaped->id])->update(['week_start_date' => null]);
        \DB::table('time_entries')->where('id', $datetimeShaped->id)->update(['local_date' => '2026-10-01 00:00:00']);

        $migration = require database_path('migrations/2026_10_02_000001_add_week_start_date_to_time_entries_table.php');
        $migration->backfill();

        $this->assertSame('2026-09-28', \DB::table('time_entries')->where('id', $legacy->id)->value('week_start_date'));
        $this->assertSame('2026-09-28', \DB::table('time_entries')->where('id', $datetimeShaped->id)->value('week_start_date'));
    }
}
