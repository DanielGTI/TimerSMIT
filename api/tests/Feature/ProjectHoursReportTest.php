<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Holiday;
use App\Models\HourBankMovement;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use ZipArchive;

/**
 * Relatório mensal de horas por projeto e pessoa, no formato enviado ao
 * administrativo. Cenário de julho/2026: 23 dias úteis, feriado em 09/07 e
 * folga do banco de horas em 10/07 → base de 168h.
 */
class ProjectHoursReportTest extends TestCase
{
    use RefreshDatabase;

    private const H = 3600;

    private Tenant $tenant;

    private Member $admin;

    private Member $gustavo;

    private Member $willian;

    private Project $sarc;

    private Project $meetings;

    private Project $inspinia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-03 10:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->admin = Member::factory()->for($this->tenant)->create(['display_name' => 'Daniel Admin']);
        $this->gustavo = Member::factory()->for($this->tenant)->create(['display_name' => 'Gustavo Henrique']);
        $this->willian = Member::factory()->for($this->tenant)->create(['display_name' => 'Willian de Sena']);

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        $this->sarc = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'SARC']);
        $this->meetings = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Reuniões SMIT']);
        $this->inspinia = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'Laravel-Inspinia', 'counts_as_idle' => true]);

        Holiday::query()->create(['tenant_id' => $this->tenant->id, 'date' => '2026-07-09', 'name' => 'Revolução Constitucionalista']);

        $this->entry($this->gustavo, $this->sarc, '2026-07-01', 160 * 60);
        $this->entry($this->gustavo, $this->meetings, '2026-07-02', 3 * 60 + 6);
        $this->entry($this->gustavo, $this->inspinia, '2026-07-03', 2 * 60);
        $this->entry($this->willian, $this->sarc, '2026-07-06', 61 * 60 + 30);
        // Fora do mês: não conta.
        $this->entry($this->willian, $this->sarc, '2026-08-03', 60);
    }

    private function entry(Member $member, Project $project, string $date, int $minutes): void
    {
        TimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $project->id,
            'member_id' => $member->id,
            'local_date' => $date,
            'timezone' => 'America/Sao_Paulo',
            'duration_seconds' => $minutes * 60,
        ]);
    }

    private function dayOff(Member $member, string $date, int $hours = 8): void
    {
        HourBankMovement::query()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $member->id,
            'kind' => HourBankMovement::KIND_TIME_OFF,
            'seconds' => -$hours * self::H,
            'local_date' => $date,
            'note' => 'Emenda do feriado',
        ]);
    }

    private function headers(?Member $as = null): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->admin)->id);

        return ['Authorization' => "Bearer {$session->token}"];
    }

    public function test_hours_by_project_and_person_with_idle_hours_against_the_workday_base(): void
    {
        $this->dayOff($this->gustavo, '2026-07-10');
        $this->dayOff($this->willian, '2026-07-10');

        $report = $this->getJson('/api/project-hours?month=2026-07', $this->headers())->assertOk()->json();

        $this->assertSame(23, $report['weekdays']);
        $this->assertSame('Base de jornada: 168h (23 dias úteis; 09/07 feriado; 10/07 banco de horas). Horas Ociosas incluem Laravel-Inspinia.', $report['note']);

        $this->assertSame(['Gustavo Henrique', 'Willian de Sena'], array_column($report['people'], 'name'));
        [$gustavo, $willian] = $report['people'];
        $this->assertSame(168 * self::H, $gustavo['baseSeconds']);
        $this->assertSame((163 * 60 + 6) * 60, $gustavo['totalSeconds']);
        $this->assertSame((4 * 60 + 54) * 60, $gustavo['idleSeconds']);
        $this->assertSame(2 * self::H, $gustavo['idleProjectSeconds']);
        $this->assertSame((106 * 60 + 30) * 60, $willian['idleSeconds']);

        $rows = collect($report['projects'])->keyBy('name');
        $this->assertSame(['Laravel-Inspinia', 'Reuniões SMIT', 'SARC'], $rows->keys()->all());
        // O projeto que conta como ociosa fica zerado: as horas dele estão em Horas Ociosas.
        $this->assertSame(0, $rows['Laravel-Inspinia']['totalSeconds']);
        $this->assertTrue($rows['Laravel-Inspinia']['countsAsIdle']);
        $this->assertSame((221 * 60 + 30) * 60, $rows['SARC']['totalSeconds']);
        $this->assertSame((61 * 60 + 30) * 60, $rows['SARC']['seconds'][(string) $this->willian->id]);

        $this->assertSame(((163 * 60 + 6) + (61 * 60 + 30)) * 60, $report['totals']['totalSeconds']);
        $this->assertSame(((4 * 60 + 54) + (106 * 60 + 30)) * 60, $report['totals']['idleSeconds']);
    }

    public function test_an_individual_day_off_lowers_only_that_persons_base(): void
    {
        $this->dayOff($this->gustavo, '2026-07-10');
        // Folga em fim de semana ou feriado não muda a base.
        $this->dayOff($this->willian, '2026-07-09');

        $report = $this->getJson('/api/project-hours?month=2026-07', $this->headers())->assertOk()->json();

        $this->assertSame(168 * self::H, $report['people'][0]['baseSeconds']);
        $this->assertSame(176 * self::H, $report['people'][1]['baseSeconds']);
        $this->assertSame(
            'Base de jornada: 176h (23 dias úteis; 09/07 feriado). Menos folgas do banco de horas: Gustavo Henrique 10/07 (base 168h). Horas Ociosas incluem Laravel-Inspinia.',
            $report['note'],
        );
    }

    public function test_daily_hours_and_approved_weeks_are_options(): void
    {
        $report = $this->getJson('/api/project-hours?month=2026-07&dailyHours=6', $this->headers())->assertOk()->json();
        $this->assertSame(22 * 6 * self::H, $report['people'][0]['baseSeconds']);

        // Nenhuma semana aprovada: só com semanas aprovadas, não sobra ninguém.
        $this->getJson('/api/project-hours?month=2026-07&approvedOnly=1', $this->headers())
            ->assertOk()
            ->assertJsonCount(0, 'people');
    }

    public function test_exports_the_spreadsheet_in_the_administrative_format(): void
    {
        $this->dayOff($this->gustavo, '2026-07-10');
        $this->dayOff($this->willian, '2026-07-10');

        $response = $this->get('/api/project-hours.xlsx?month=2026-07', $this->headers());
        $response->assertOk();
        $this->assertStringContainsString('horas_por_projeto_2026-07.xlsx', $response->headers->get('Content-Disposition'));

        $path = $response->baseResponse->getFile()->getPathname();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path));
        $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
        $styles = $zip->getFromName('xl/styles.xml');
        $zip->close();

        $this->assertStringContainsString('formatCode="[h]:mm:ss"', $styles);
        $this->assertStringContainsString('<t xml:space="preserve">Julho de 2026</t>', $sheet);
        $this->assertStringContainsString('Base de jornada: 168h (23 dias úteis; 09/07 feriado; 10/07 banco de horas). Horas Ociosas incluem Laravel-Inspinia.', $sheet);
        $this->assertStringContainsString('<c r="A4" s="3" t="inlineStr"><is><t xml:space="preserve">Projetos</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="D4" s="3" t="inlineStr"><is><t xml:space="preserve">TOTAL</t></is></c>', $sheet);
        // Linha 8: Horas Ociosas; B8 = 4:54 do Gustavo (17640 s / 86400).
        $this->assertStringContainsString('<c r="A8" s="6" t="inlineStr"><is><t xml:space="preserve">Horas Ociosas</t></is></c>', $sheet);
        $this->assertStringContainsString('<c r="B8" s="7"><v>0.2041666667</v></c>', $sheet);
        $this->assertStringContainsString('<c r="A9" s="8" t="inlineStr"><is><t xml:space="preserve">TOTAL</t></is></c>', $sheet);
        $this->assertTrue(AuditEvent::query()->where('action', 'project_hours.exported')->exists());
    }

    public function test_only_admins_and_the_project_flag_is_set_in_settings(): void
    {
        $this->getJson('/api/project-hours?month=2026-07', $this->headers($this->gustavo))->assertForbidden();

        $this->patchJson("/api/settings/projects/{$this->sarc->id}", ['countsAsIdle' => true], $this->headers())
            ->assertOk()
            ->assertJsonPath('projects.2.name', 'SARC')
            ->assertJsonPath('projects.2.countsAsIdle', true)
            ->assertJsonPath('projects.2.enabled', true);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.project_idle_changed')->exists());

        $this->patchJson("/api/settings/projects/{$this->sarc->id}", [], $this->headers())->assertStatus(422);
    }
}
