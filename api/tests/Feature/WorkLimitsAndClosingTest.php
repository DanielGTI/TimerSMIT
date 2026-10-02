<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Services\SessionTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Fase 3: avisos de limite de jornada na semana (2h extras/dia, limite
 * semanal, 11h de descanso) e o fechamento mensal para o DP (JSON e CSV).
 *
 * Semana de referência: seg 2026-09-28 a dom 2026-10-04.
 */
class WorkLimitsAndClosingTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-09-28';

    private const TZ = 'America/Sao_Paulo';

    private const H = 3600;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-09-30 15:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => self::TZ]);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create(['display_name' => 'Ana Técnica']);
        $this->admin = Member::factory()->for($this->tenant)->create(['display_name' => 'Daniel Admin']);

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);

        $this->rule();
    }

    private function rule(array $overrides = [], int $version = 1): OvertimeRule
    {
        return OvertimeRule::query()->create($overrides + [
            'tenant_id' => $this->tenant->id,
            'version' => $version,
            'enabled' => true,
            'effective_from' => now()->subDay(),
        ]);
    }

    private function headers(?Member $as = null, string $key = 'limits-key-000000001'): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key];
    }

    private function entry(string $date, ?string $start, int $minutes): TimeEntry
    {
        $attributes = [
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'local_date' => $date,
            'timezone' => self::TZ,
            'duration_seconds' => $minutes * 60,
        ];

        if ($start !== null) {
            $from = CarbonImmutable::parse("{$date} {$start}", self::TZ);
            $attributes['started_at_utc'] = $from->utc();
            $attributes['ended_at_utc'] = $from->addMinutes($minutes)->utc();
        }

        return TimeEntry::factory()->create($attributes);
    }

    private function alerts(string $week = self::WEEK): array
    {
        return $this->getJson("/api/me/weeks/{$week}", $this->headers())->assertOk()->json('alerts');
    }

    // ---------- avisos ----------

    public function test_more_than_two_extra_hours_on_a_weekday_is_a_warning(): void
    {
        $this->entry('2026-09-29', '09:00', 12 * 60 + 30); // 3h30 depois das 18h
        $this->entry('2026-09-30', '09:00', 10 * 60);      // 1h depois: dentro do limite

        $daily = array_values(array_filter($this->alerts(), fn ($alert) => $alert['type'] === 'daily_extra'));

        $this->assertSame([['type' => 'daily_extra', 'date' => '2026-09-29', 'seconds' => 12600, 'limitSeconds' => 2 * self::H]], $daily);
    }

    public function test_a_week_above_the_weekly_limit_is_a_warning(): void
    {
        foreach (['2026-09-28', '2026-09-29', '2026-09-30', '2026-10-01', '2026-10-02'] as $date) {
            $this->entry($date, '08:00', 9 * 60);
        }

        $weekly = array_values(array_filter($this->alerts(), fn ($alert) => $alert['type'] === 'weekly_hours'));

        $this->assertSame([['type' => 'weekly_hours', 'date' => self::WEEK, 'seconds' => 45 * self::H, 'limitSeconds' => 44 * self::H]], $weekly);
    }

    public function test_less_than_eleven_hours_between_two_workdays_is_a_warning(): void
    {
        $this->entry('2026-09-29', '14:00', 9 * 60);  // até 23:00
        $this->entry('2026-09-30', '07:00', 4 * 60);  // 8h de descanso
        // Timer que atravessa a meia-noite (dois lançamentos emendados) é um bloco só:
        // de 02:00 até 13:00 são 11h, sem aviso.
        $this->entry('2026-10-01', '20:00', 4 * 60);
        $this->entry('2026-10-02', '00:00', 2 * 60);
        $this->entry('2026-10-02', '13:00', 2 * 60);
        // Lançamento sem horário não entra na conta.
        $this->entry('2026-10-03', null, 60);

        $rests = array_values(array_filter($this->alerts(), fn ($alert) => $alert['type'] === 'rest'));

        $this->assertSame([[
            'type' => 'rest',
            'date' => '2026-09-30',
            'seconds' => 8 * self::H,
            'limitSeconds' => 11 * self::H,
            'previousEnd' => '2026-09-29 23:00',
            'nextStart' => '2026-09-30 07:00',
        ]], $rests);
    }

    public function test_the_rest_before_monday_counts_the_previous_sunday(): void
    {
        $this->entry('2026-09-27', '20:00', 3 * 60); // domingo até 23:00
        $this->entry('2026-09-28', '08:00', 60);

        $this->assertSame(['rest'], array_column($this->alerts(), 'type'));
    }

    public function test_no_warnings_for_pj_when_the_control_is_off_or_the_limit_is_zero(): void
    {
        $this->entry('2026-09-29', '09:00', 13 * 60);

        $this->member->update(['hours_regime' => Member::REGIME_PJ]);
        $this->assertSame([], $this->alerts());

        $this->member->update(['hours_regime' => Member::REGIME_CLT]);
        $this->rule(['alert_daily_extra_minutes' => 0], 2);
        $this->assertSame([], $this->alerts());

        $this->rule(['enabled' => false], 3);
        $this->assertSame([], $this->alerts());
    }

    public function test_the_limits_are_part_of_the_versioned_rules(): void
    {
        $payload = [
            'enabled' => true,
            'workdayStart' => '09:00',
            'workdayEnd' => '18:00',
            'factorWeekday' => 1.5,
            'factorSaturday' => 1.5,
            'factorSunday' => 2,
            'factorHoliday' => 2,
            'nightStart' => '22:00',
            'nightEnd' => '05:00',
            'nightPercent' => 20,
            'nightReducedHour' => true,
            'requireTimeOfDay' => true,
        ];

        $this->getJson('/api/settings', $this->headers($this->admin))
            ->assertJsonPath('overtime.alertDailyExtraHours', 2)
            ->assertJsonPath('overtime.alertWeeklyHours', 44)
            ->assertJsonPath('overtime.alertRestHours', 11);

        $this->putJson('/api/settings/overtime-rules', $payload + ['alertWeeklyHours' => 40, 'alertDailyExtraHours' => 1.5], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('overtime.alertWeeklyHours', 40)
            ->assertJsonPath('overtime.alertDailyExtraHours', 1.5)
            ->assertJsonPath('overtime.alertRestHours', 11);

        $this->putJson('/api/settings/overtime-rules', $payload + ['alertRestHours' => 25], $this->headers($this->admin))->assertStatus(422);
    }

    public function test_the_approver_sees_the_warnings_of_the_week(): void
    {
        $this->entry('2026-09-29', '09:00', 12 * 60 + 30);
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers(key: 'submit-key-0000000001'))->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();

        $this->getJson("/api/approvals/{$submission->id}", $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('week.alerts.0.type', 'daily_extra');
    }

    // ---------- fechamento mensal ----------

    /** Quinta 01/10 17h–20h (2h extras) e sábado 03/10 4h; semana aprovada. */
    private function approvedWeekWithAdditionalHours(): array
    {
        $thursday = $this->entry('2026-10-01', '17:00', 3 * 60);
        $saturday = $this->entry('2026-10-03', null, 4 * 60);

        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers(key: 'submit-key-0000000001'))->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve'], $this->headers($this->admin, 'decide-key-0000000001'))
            ->assertOk();

        return [$thursday, $saturday];
    }

    private function classify(TimeEntry $entry, string $classification): void
    {
        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$entry->id], 'classification' => $classification], $this->headers($this->admin))
            ->assertOk();
    }

    public function test_the_monthly_closing_shows_hours_by_destination_and_factor(): void
    {
        [$thursday, $saturday] = $this->approvedWeekWithAdditionalHours();
        $this->classify($thursday, 'overtime');
        $this->classify($saturday, 'bank');
        $this->entry('2026-10-10', null, 2 * 60); // sábado de semana não aprovada

        $this->travelTo('2026-10-12 10:00:00');
        $this->postJson("/api/hour-bank/{$this->member->id}/movements", ['kind' => 'time_off', 'seconds' => 4 * self::H, 'localDate' => '2026-10-09', 'note' => 'Folga'], $this->headers($this->admin, 'move-key-00000000001'))
            ->assertOk();

        $closing = $this->getJson('/api/additional-hours/closing?month=2026-10', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('from', '2026-10-01')
            ->assertJsonPath('to', '2026-10-31')
            ->json();

        $ana = collect($closing['members'])->firstWhere('memberId', (string) $this->member->id);
        $this->assertSame(['seconds' => 2 * self::H, 'nightSeconds' => 0, 'weightedSeconds' => 3 * self::H], $ana['totals']['overtime']);
        $this->assertSame(6 * self::H, $ana['totals']['bank']['weightedSeconds']);
        $this->assertSame(2 * self::H, $ana['totals']['unapproved']['seconds']);
        $this->assertSame(0, $ana['totals']['pending']['seconds']);
        $this->assertEquals(1.5, $ana['lines'][0]['factor']);
        $this->assertSame([
            'creditedSeconds' => 6 * self::H,
            'timeOffSeconds' => 4 * self::H,
            'payoutSeconds' => 0,
            'adjustmentSeconds' => 0,
            'expiredSeconds' => 0,
            'balanceSeconds' => 2 * self::H,
        ], $ana['bank']);

        // Setembro não tem as horas de outubro.
        $this->getJson('/api/additional-hours/closing?month=2026-09', $this->headers($this->admin))->assertJsonCount(0, 'members');
    }

    public function test_the_closing_exports_a_csv_for_payroll(): void
    {
        [$thursday, $saturday] = $this->approvedWeekWithAdditionalHours();
        $this->classify($thursday, 'overtime');

        $response = $this->get('/api/additional-hours/closing.csv?month=2026-10', $this->headers($this->admin));
        $response->assertOk();
        $this->assertStringContainsString('fechamento_2026-10.csv', $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $rows = array_map(
            fn (string $line) => str_getcsv($line, ';', '"', ''),
            array_values(array_filter(explode("\r\n", substr($csv, 3)))),
        );

        $this->assertSame(['Mês', 'Pessoa', 'Regime', 'Categoria', 'Fator', 'Horas (HH:MM)', 'Horas (decimal)', 'Noturnas (HH:MM)', 'Ponderadas (HH:MM)', 'Ponderadas (decimal)', 'Ocorrências'], $rows[0]);
        $this->assertContains(['2026-10', 'Ana Técnica', 'CLT', 'Hora extra', '1,50', '02:00', '2,00', '00:00', '03:00', '3,00', ''], $rows);
        $this->assertContains(['2026-10', 'Ana Técnica', 'CLT', 'A classificar (semana aprovada)', '', '04:00', '4,00', '00:00', '06:00', '6,00', ''], $rows);
        $this->assertTrue(AuditEvent::query()->where('action', 'closing.exported')->exists());
    }

    public function test_only_admins_see_the_closing(): void
    {
        $this->getJson('/api/additional-hours/closing?month=2026-10', $this->headers())->assertForbidden();
        $this->getJson('/api/additional-hours/closing?month=2026-13', $this->headers($this->admin))->assertStatus(422);
    }
}
