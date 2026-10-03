<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Models\WorkItemSnapshot;
use App\Services\SessionTokenService;
use App\Support\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Importação do relatório "Times Explorer" do 7pace (.xlsx): simulação,
 * mapeamento de pessoas e projetos, nada duplicado, semanas travadas
 * respeitadas e sem efeito nas regras de hora adicional.
 */
class SevenPaceImportTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private const SARC_GUID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    private Tenant $tenant;

    private Member $admin;

    private Member $willian;

    private Member $jhones;

    private Project $meetings;

    private array $rows = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-03 10:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => self::TZ]);
        $this->admin = Member::factory()->for($this->tenant)->create(['display_name' => 'Daniel Admin']);
        $this->willian = Member::factory()->for($this->tenant)->create(['display_name' => 'Willian de Sena Chiquinato']);
        $this->jhones = Member::factory()->for($this->tenant)->create(['display_name' => 'Jhones Michael Santana Vieira']);
        $this->meetings = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Reuniões SMIT']);

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        // Controle de horas adicionais ligado desde o "deploy" (outubro).
        OvertimeRule::query()->create(['tenant_id' => $this->tenant->id, 'version' => 1, 'enabled' => true, 'effective_from' => now()->subDay()]);

        $this->rows = [
            ['Willian de Sena Chiquinato', 15703, 'Daily 01/09', '2026-09-01 09:10', '2026-09-01 09:28', 'Reuniões SMIT', 'Reunião Interna', 'Task', 'Reuniões SMIT', ''],
            ['Jhones', 15800, 'Tela de login', '2026-09-02 10:00', '2026-09-02 12:00', 'SARC', 'Desenvolvimento', 'User Story', 'SARC\\Sprint 1', 'Ajuste no login'],
            ['Fulano Desconhecido', 1, 'Qualquer', '2026-09-03 10:00', '2026-09-03 11:00', 'SARC', 'Desenvolvimento', 'Task', 'SARC', ''],
            ['Willian de Sena Chiquinato', 15900, 'Plantão', '2026-09-05 09:00', '2026-09-05 13:00', 'Reuniões SMIT', '[Not Set]', 'Task', 'Reuniões SMIT', ''],
            ['Willian de Sena Chiquinato', 15901, 'Pesquisa', '2026-09-08 14:00', '2026-09-08 15:00', 'Reuniões SMIT', 'Pesquisa Nova', 'Task', 'Reuniões SMIT', ''],
        ];
    }

    /** Planilha no formato do 7pace: título, "Created", títulos das colunas e as linhas. */
    private function file(): string
    {
        $serial = fn (string $local) => CarbonImmutable::parse($local, 'UTC')->getTimestamp() / 86400 + 25569;

        $sheet = new XlsxWriter;
        $sheet->text(1, 1, 'Timetracker Report: Times Explorer')->text(1, 3, 'Created');
        $sheet->number(2, 3, 46298.1197);
        foreach (['Hours', 'Person', 'Work Item', 'Title', 'Date', 'Start', 'End', 'Team Project', 'Activity Type', 'Work Item Type', 'Iteration Path', 'Comment'] as $col => $title) {
            $sheet->text(3, $col + 1, $title);
        }

        foreach ($this->rows as $index => [$person, $workItem, $title, $start, $end, $project, $activity, $type, $iteration, $comment]) {
            $row = $index + 4;
            $hours = (CarbonImmutable::parse($end)->getTimestamp() - CarbonImmutable::parse($start)->getTimestamp()) / 3600;
            $sheet->number($row, 1, $hours)
                ->text($row, 2, $person)
                ->number($row, 3, $workItem)
                ->text($row, 4, $title)
                ->number($row, 5, $serial($start))
                ->number($row, 6, $serial($start))
                ->number($row, 7, $serial($end))
                ->text($row, 8, $project)
                ->text($row, 9, $activity)
                ->text($row, 10, $type)
                ->text($row, 11, $iteration)
                ->text($row, 12, $comment);
        }

        $path = tempnam(sys_get_temp_dir(), 'test-7pace-');
        $sheet->save($path, 'Data');
        $content = base64_encode((string) file_get_contents($path));
        unlink($path);

        return $content;
    }

    private function headers(?Member $as = null): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->admin)->id);

        return ['Authorization' => "Bearer {$session->token}"];
    }

    private function send(bool $dryRun, array $extra = [])
    {
        return $this->postJson('/api/settings/import/7pace', ['file' => $this->file(), 'dryRun' => $dryRun] + $extra, $this->headers());
    }

    public function test_the_dry_run_matches_people_and_projects_and_writes_nothing(): void
    {
        $preview = $this->send(true)->assertOk()->json();

        $this->assertSame(5, $preview['rows']);
        $this->assertSame('2026-09-01', $preview['from']);
        $this->assertSame('2026-09-08', $preview['to']);

        $people = collect($preview['people'])->keyBy('name');
        $this->assertSame('exact', $people['Willian de Sena Chiquinato']['match']);
        $this->assertSame((string) $this->jhones->id, $people['Jhones']['memberId']);
        $this->assertSame('prefix', $people['Jhones']['match']);
        $this->assertNull($people['Fulano Desconhecido']['memberId']);

        $projects = collect($preview['projects'])->keyBy('name');
        $this->assertSame('existing', $projects['Reuniões SMIT']['status']);
        $this->assertSame('missing', $projects['SARC']['status']);
        $this->assertSame(15800, $projects['SARC']['sampleWorkItemId']);

        $activities = collect($preview['activities'])->keyBy('name');
        $this->assertSame('existing', $activities['Reunião Interna']['status']);
        $this->assertSame('none', $activities['[Not Set]']['status']);
        $this->assertSame('create', $activities['Pesquisa Nova']['status']);

        // Sem o GUID do SARC, as linhas dele ficam de fora; a do desconhecido também.
        $this->assertSame(3, $preview['toImport']);
        $this->assertSame(['unknownPerson' => 1, 'unknownProject' => 1, 'alreadyImported' => 0, 'alreadyLogged' => 0, 'lockedWeek' => 0], $preview['skipped']);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_imports_entries_creating_missing_projects_and_never_duplicates(): void
    {
        $result = $this->send(false, ['projectIds' => ['SARC' => self::SARC_GUID]])->assertOk()->json();

        $this->assertSame(4, $result['imported']);
        $sarc = Project::query()->where('devops_project_id', self::SARC_GUID)->firstOrFail();
        $this->assertSame('SARC', $sarc->devops_project_name);

        $login = TimeEntry::query()->where('devops_work_item_id', 15800)->firstOrFail();
        $this->assertSame($this->jhones->id, $login->member_id);
        $this->assertSame($sarc->id, $login->project_id);
        $this->assertSame('2026-09-02', substr((string) $login->local_date, 0, 10));
        $this->assertSame(7200, $login->duration_seconds);
        $this->assertSame('10:00', $login->localStartTime());
        $this->assertSame('12:00', $login->localEndTime());
        $this->assertSame('Ajuste no login', $login->note);
        $this->assertFalse($login->billable);
        $this->assertSame('Desenvolvimento', ActivityType::query()->find($login->activity_type_id)->name);
        $this->assertSame('2026-09-02', $login->created_at->setTimezone(self::TZ)->toDateString());
        $this->assertStringStartsWith('7pace:', $login->import_ref);

        $snapshot = WorkItemSnapshot::query()->where('devops_work_item_id', 15800)->firstOrFail();
        $this->assertSame('Tela de login', $snapshot->title);
        $this->assertSame('User Story', $snapshot->work_item_type);
        $this->assertSame('SARC\\Sprint 1', $snapshot->iteration_path);

        $this->assertNull(TimeEntry::query()->where('devops_work_item_id', 15900)->firstOrFail()->activity_type_id);
        $this->assertTrue(ActivityType::query()->where('tenant_id', $this->tenant->id)->where('name', 'Pesquisa Nova')->exists());
        $this->assertTrue(AuditEvent::query()->where('action', 'import.seven_pace')->exists());

        // De novo, o mesmo arquivo: nada entra.
        $again = $this->send(false, ['projectIds' => ['SARC' => self::SARC_GUID]])->assertOk()->json();
        $this->assertSame(0, $again['imported']);
        $this->assertSame(4, $again['skipped']['alreadyImported']);
        $this->assertSame(4, TimeEntry::query()->count());
    }

    public function test_september_imports_do_not_become_additional_hours(): void
    {
        $this->send(false)->assertOk();

        // Sábado 05/09, 4h: antes do controle existir, não vira hora adicional.
        $session = app(SessionTokenService::class)->issue($this->tenant->id, $this->willian->id);
        $entry = collect($this->getJson('/api/me/weeks/2026-08-31', ['Authorization' => "Bearer {$session->token}"])->assertOk()->json('entries'))
            ->firstWhere('workItemId', 15900);
        $this->assertNull($entry['additional']);
    }

    public function test_skips_what_is_already_logged_and_locked_weeks(): void
    {
        TimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->meetings->id,
            'member_id' => $this->willian->id,
            'local_date' => '2026-09-01',
            'timezone' => self::TZ,
            'duration_seconds' => 1800,
            'started_at_utc' => CarbonImmutable::parse('2026-09-01 09:00', self::TZ)->utc(),
            'ended_at_utc' => CarbonImmutable::parse('2026-09-01 09:30', self::TZ)->utc(),
        ]);
        WeeklySubmission::query()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->willian->id,
            'week_start_date' => '2026-09-07',
            'status' => WeeklySubmission::STATUS_APPROVED,
            'revision' => 1,
        ]);

        $preview = $this->send(true, ['projectIds' => ['SARC' => self::SARC_GUID]])->assertOk()->json();

        $this->assertSame(1, $preview['skipped']['alreadyLogged']);
        $this->assertSame(1, $preview['skipped']['lockedWeek']);
        $this->assertSame(2, $preview['toImport']);
        $this->assertStringContainsString('Willian de Sena Chiquinato — semana de 07/09', $preview['warnings'][0]);
    }

    public function test_a_person_can_be_mapped_by_hand(): void
    {
        $fulano = Member::factory()->for($this->tenant)->create(['display_name' => 'Fulano de Tal']);

        $preview = $this->send(true, [
            'personMap' => ['Fulano Desconhecido' => (string) $fulano->id],
            'projectIds' => ['SARC' => self::SARC_GUID],
        ])->assertOk()->json();

        $this->assertSame('manual', collect($preview['people'])->firstWhere('name', 'Fulano Desconhecido')['match']);
        $this->assertSame(5, $preview['toImport']);
    }

    public function test_only_admins_and_only_valid_spreadsheets(): void
    {
        $this->postJson('/api/settings/import/7pace', ['file' => $this->file(), 'dryRun' => true], $this->headers($this->willian))->assertForbidden();

        $this->postJson('/api/settings/import/7pace', ['file' => base64_encode('não é planilha'), 'dryRun' => true], $this->headers())
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->send(true, ['projectIds' => ['SARC' => 'nao-e-guid']])->assertStatus(422);
    }
}
