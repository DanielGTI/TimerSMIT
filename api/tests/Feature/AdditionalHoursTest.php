<?php

namespace Tests\Feature;

use App\Models\AdditionalHourReview;
use App\Models\AuditEvent;
use App\Models\Holiday;
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
 * Horas adicionais (fora do expediente, fim de semana, feriado): cálculo na
 * semana, De/Até obrigatório, "não autorizada" pelo aprovador, classificação
 * pelo administrador (hora extra, banco, a pagar) e configuração.
 *
 * Semana de referência: seg 2026-09-28 a dom 2026-10-04; "agora" é qua 30/09 15h.
 */
class AdditionalHoursTest extends TestCase
{
    use RefreshDatabase;

    private const WEEK = '2026-09-28';

    private const TZ = 'America/Sao_Paulo';

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
            'effective_from' => now(),
        ]);
    }

    private function headers(?Member $as = null, string $key = 'additional-key-000001'): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key];
    }

    private function entry(string $date, ?string $start, int $minutes, ?Member $member = null): TimeEntry
    {
        $attributes = [
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => ($member ?? $this->member)->id,
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

    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-09-29',
            'durationSeconds' => 3600,
        ];
    }

    /** Envia a semana da pessoa e aprova como administrador. */
    private function submitAndApprove(array $unauthorized = [], ?Member $member = null)
    {
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers($member, 'submit-key-0000000001'))->assertOk();
        $submission = WeeklySubmission::query()->where('member_id', ($member ?? $this->member)->id)->firstOrFail();

        return $this->postJson(
            "/api/approvals/{$submission->id}/decision",
            ['decision' => 'approve', 'unauthorized' => $unauthorized],
            $this->headers($this->admin, 'decide-key-0000000001'),
        );
    }

    private function week(?Member $as = null)
    {
        return $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers($as))->assertOk();
    }

    // ---------- De/Até obrigatório ----------

    public function test_manual_entries_require_the_time_of_day_while_the_control_is_on(): void
    {
        $this->postJson('/api/entries', $this->payload(), $this->headers(key: 'entry-key-0000000001'))
            ->assertStatus(422)
            ->assertJsonValidationErrors('startTime');

        $this->postJson('/api/entries', $this->payload(['startTime' => '17:00']), $this->headers(key: 'entry-key-0000000002'))
            ->assertCreated();
    }

    public function test_the_time_of_day_cannot_be_removed_from_a_manual_entry(): void
    {
        $entry = $this->entry('2026-09-29', '10:00', 60);

        $this->patchJson("/api/entries/{$entry->id}", ['startTime' => null], $this->headers() + ['If-Match' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors('startTime');
    }

    public function test_no_time_is_required_when_the_control_is_off_or_the_person_does_not_track_hours(): void
    {
        $this->member->update(['hours_regime' => Member::REGIME_NONE]);
        $this->postJson('/api/entries', $this->payload(), $this->headers(key: 'entry-key-0000000003'))->assertCreated();

        $this->member->update(['hours_regime' => Member::REGIME_CLT]);
        $this->travel(1)->minutes();
        $this->rule(['enabled' => false], version: 2);
        $this->postJson('/api/entries', $this->payload(), $this->headers(key: 'entry-key-0000000004'))->assertCreated();
    }

    public function test_me_tells_the_form_whether_the_time_is_required(): void
    {
        $this->getJson('/api/me', $this->headers())
            ->assertOk()
            ->assertJsonPath('overtime.enabled', true)
            ->assertJsonPath('overtime.requireTimeOfDay', true)
            ->assertJsonPath('overtime.workdayEnd', '18:00')
            ->assertJsonPath('hoursRegime', 'clt');
    }

    // ---------- semana ----------

    public function test_the_week_shows_additional_hours_to_validate_with_the_factors(): void
    {
        $inside = $this->entry('2026-09-29', '10:00', 120); // dentro do expediente
        $evening = $this->entry('2026-09-29', '17:00', 180); // 2h depois das 18h
        $saturday = $this->entry('2026-10-03', null, 600); // sábado inteiro

        $response = $this->week();

        $entries = collect($response->json('entries'))->keyBy('id');
        $this->assertNull($entries[(string) $inside->id]['additional']);
        $this->assertSame(
            [
                'seconds' => 7200, 'weightedSeconds' => 10800, 'nightSeconds' => 0, 'dayType' => 'weekday', 'status' => 'pending', 'denied' => null,
                // Sem pedido aprovado: hora extra sujeita à aprovação (Fase 4).
                'coverage' => [
                    'kind' => 'none', 'coveredSeconds' => 0, 'uncoveredSeconds' => 7200, 'requestId' => null,
                    'afterTheFact' => null, 'suggestedDestination' => null, 'requestPending' => false,
                ],
            ],
            $entries[(string) $evening->id]['additional'],
        );
        $this->assertSame(54000, $entries[(string) $saturday->id]['additional']['weightedSeconds']); // 10h × 1,5 = 15h
        $response->assertJsonPath('additionalTotals', ['seconds' => 43200, 'weightedSeconds' => 64800, 'pendingSeconds' => 43200]);
    }

    public function test_holidays_turn_the_whole_day_into_additional_hours(): void
    {
        Holiday::query()->create(['tenant_id' => $this->tenant->id, 'date' => '2026-09-29', 'name' => 'Feriado municipal']);
        $entry = $this->entry('2026-09-29', '10:00', 120);

        $additional = collect($this->week()->json('entries'))->firstWhere('id', (string) $entry->id)['additional'];

        $this->assertSame('holiday', $additional['dayType']);
        $this->assertSame(14400, $additional['weightedSeconds']); // 2h × 2
    }

    public function test_a_rule_change_only_applies_to_entries_made_after_it(): void
    {
        $before = $this->entry('2026-09-29', '18:00', 60);

        $this->travel(1)->minutes();
        $this->putJson('/api/settings/overtime-rules', $this->rulePayload(['factorWeekday' => 2]), $this->headers($this->admin))->assertOk();
        $after = $this->entry('2026-09-30', '18:00', 60);

        $entries = collect($this->week()->json('entries'))->keyBy('id');
        $this->assertSame(5400, $entries[(string) $before->id]['additional']['weightedSeconds']); // ainda 1,5
        $this->assertSame(7200, $entries[(string) $after->id]['additional']['weightedSeconds']); // 2
    }

    public function test_people_who_do_not_track_hours_never_have_additional_hours(): void
    {
        $this->member->update(['hours_regime' => Member::REGIME_NONE]);
        $this->entry('2026-10-03', null, 600);

        $this->week()->assertJsonPath('additionalTotals.seconds', 0);
    }

    // ---------- aprovação ----------

    public function test_the_approver_can_mark_additional_hours_as_not_authorized(): void
    {
        $entry = $this->entry('2026-10-03', null, 240);

        $this->submitAndApprove([['entryId' => $entry->id, 'reason' => 'Sem pedido prévio do gestor']])->assertOk();

        $additional = collect($this->week()->json('entries'))->firstWhere('id', (string) $entry->id)['additional'];
        $this->assertSame(['reason' => 'Sem pedido prévio do gestor'], $additional['denied']);
        $this->assertSame('pending', $additional['status']); // continua registrada; o administrador decide

        $this->assertTrue(AuditEvent::query()->where('action', 'week.additional_hours_denied')->exists());
    }

    public function test_only_entries_with_additional_hours_can_be_marked_and_a_reason_is_required(): void
    {
        $inside = $this->entry('2026-09-29', '10:00', 60);
        $saturday = $this->entry('2026-10-03', null, 60);

        $this->submitAndApprove([['entryId' => $inside->id, 'reason' => 'x']])->assertStatus(422);

        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson(
            "/api/approvals/{$submission->id}/decision",
            ['decision' => 'approve', 'unauthorized' => [['entryId' => $saturday->id, 'reason' => '   ']]],
            $this->headers($this->admin, 'decide-key-0000000002'),
        )->assertStatus(422);

        $this->assertSame(WeeklySubmission::STATUS_SUBMITTED, $submission->fresh()->status);
    }

    // ---------- classificação pelo administrador ----------

    public function test_the_admin_queue_lists_only_additional_hours_of_approved_weeks(): void
    {
        $saturday = $this->entry('2026-10-03', null, 240);
        $this->entry('2026-09-29', '10:00', 60); // sem hora adicional

        // Semana ainda aberta: nada na fila.
        $this->getJson('/api/additional-hours?from=2026-09-01&to=2026-10-31', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonCount(0, 'items');

        $this->submitAndApprove()->assertOk();

        $this->getJson('/api/additional-hours?from=2026-09-01&to=2026-10-31', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.entryId', (string) $saturday->id)
            ->assertJsonPath('items.0.memberName', 'Ana Técnica')
            ->assertJsonPath('items.0.regime', 'clt')
            ->assertJsonPath('items.0.additional.weightedSeconds', 21600);

        $this->getJson('/api/additional-hours', $this->headers())->assertForbidden();
    }

    public function test_classifying_freezes_the_values_and_the_person_sees_the_destination(): void
    {
        $saturday = $this->entry('2026-10-03', null, 240);
        $this->submitAndApprove()->assertOk();

        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'bank', 'note' => 'Folga em novembro'], $this->headers($this->admin))
            ->assertOk()
            ->assertJson(['updated' => 1]);

        $review = AdditionalHourReview::query()->where('time_entry_id', $saturday->id)->firstOrFail();
        $this->assertSame('bank', $review->classification);
        $this->assertSame(21600, $review->weighted_seconds);
        $this->assertSame($this->admin->id, $review->classified_by);

        // Um feriado cadastrado depois não muda o que já foi decidido.
        Holiday::query()->create(['tenant_id' => $this->tenant->id, 'date' => '2026-10-03', 'name' => 'Feriado tardio']);
        $additional = collect($this->week()->json('entries'))->firstWhere('id', (string) $saturday->id)['additional'];
        $this->assertSame('bank', $additional['status']);
        $this->assertSame(21600, $additional['weightedSeconds']);

        $this->getJson('/api/additional-hours?from=2026-09-01&to=2026-10-31', $this->headers($this->admin))->assertJsonCount(0, 'items');
        $this->getJson('/api/additional-hours?from=2026-09-01&to=2026-10-31&status=classified', $this->headers($this->admin))
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.classificationNote', 'Folga em novembro');

        // Desfazer devolve para "a validar".
        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'pending'], $this->headers($this->admin))->assertOk();
        $this->assertNull($review->fresh()->classification);
        $this->assertTrue(AuditEvent::query()->where('action', 'additional_hours.classified')->count() === 2);
    }

    public function test_pj_hours_cannot_go_to_the_hour_bank_only_payable(): void
    {
        $this->member->update(['hours_regime' => Member::REGIME_PJ]);
        $saturday = $this->entry('2026-10-03', null, 120);
        $this->submitAndApprove()->assertOk();

        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'bank'], $this->headers($this->admin))
            ->assertStatus(422);
        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'payable'], $this->headers($this->admin))
            ->assertOk();
    }

    public function test_hours_of_a_week_that_is_not_approved_cannot_be_classified(): void
    {
        $saturday = $this->entry('2026-10-03', null, 120);

        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'overtime'], $this->headers($this->admin))
            ->assertStatus(409);
        $this->assertSame(0, AdditionalHourReview::query()->count());
    }

    public function test_reopening_an_approved_week_clears_the_decisions_about_its_additional_hours(): void
    {
        $saturday = $this->entry('2026-10-03', null, 120);
        $this->submitAndApprove([['entryId' => $saturday->id, 'reason' => 'Sem pedido']])->assertOk();
        $this->postJson('/api/additional-hours/classify', ['entryIds' => [$saturday->id], 'classification' => 'overtime'], $this->headers($this->admin))->assertOk();

        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/reopen", ['reason' => 'Faltou um lançamento'], $this->headers($this->admin, 'reopen-key-0000000001'))
            ->assertOk();

        $this->assertSame(0, AdditionalHourReview::query()->count());
        $this->assertSame('pending', collect($this->week()->json('entries'))->firstWhere('id', (string) $saturday->id)['additional']['status']);
    }

    // ---------- configuração ----------

    private function rulePayload(array $overrides = []): array
    {
        return $overrides + [
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
    }

    public function test_admin_sees_and_versions_the_rules(): void
    {
        $this->getJson('/api/settings', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('overtime.enabled', true)
            ->assertJsonPath('overtime.version', 1)
            ->assertJsonPath('overtime.factorSunday', 2)
            ->assertJsonPath('holidays', []);

        $this->putJson('/api/settings/overtime-rules', $this->rulePayload(['factorSaturday' => 2, 'workdayStart' => '08:00']), $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('overtime.version', 2)
            ->assertJsonPath('overtime.factorSaturday', 2)
            ->assertJsonPath('overtime.workdayStart', '08:00');

        $this->assertSame(2, OvertimeRule::query()->count());
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.overtime_rules_updated')->exists());
    }

    public function test_rule_values_are_validated_and_only_admins_change_them(): void
    {
        $this->putJson('/api/settings/overtime-rules', $this->rulePayload(['workdayEnd' => '08:00']), $this->headers($this->admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('workdayEnd');
        $this->putJson('/api/settings/overtime-rules', $this->rulePayload(['factorSunday' => 0.5]), $this->headers($this->admin))
            ->assertStatus(422)
            ->assertJsonValidationErrors('factorSunday');
        $this->putJson('/api/settings/overtime-rules', $this->rulePayload(), $this->headers())
            ->assertForbidden();
    }

    public function test_holidays_can_be_added_suggested_and_removed(): void
    {
        $this->postJson('/api/settings/holidays', ['date' => '2026-01-20', 'name' => 'São Sebastião'], $this->headers($this->admin))->assertOk();
        $this->postJson('/api/settings/holidays', ['date' => '2026-01-20', 'name' => 'Repetido'], $this->headers($this->admin))->assertStatus(422);

        $response = $this->postJson('/api/settings/holidays/national', ['year' => 2026], $this->headers($this->admin))->assertOk();
        $dates = array_column($response->json('holidays'), 'date');
        $this->assertContains('2026-04-03', $dates); // Sexta-feira Santa (Páscoa em 05/04/2026)
        $this->assertContains('2026-11-20', $dates); // Consciência Negra
        $this->assertCount(11, $dates); // 10 nacionais + o municipal

        // Repetir não duplica.
        $this->postJson('/api/settings/holidays/national', ['year' => 2026], $this->headers($this->admin))->assertJsonCount(11, 'holidays');

        $holiday = Holiday::query()->where('date', '2026-01-20')->firstOrFail();
        $this->deleteJson("/api/settings/holidays/{$holiday->id}", [], $this->headers($this->admin))->assertOk()->assertJsonCount(10, 'holidays');
    }

    public function test_the_admin_sets_each_persons_regime(): void
    {
        $this->putJson("/api/settings/members/{$this->member->id}/hours-regime", ['regime' => 'pj'], $this->headers($this->admin))
            ->assertOk();

        $this->assertSame('pj', $this->member->fresh()->hours_regime);
        $this->getJson('/api/settings', $this->headers($this->admin))
            ->assertJsonPath('members.'.$this->memberIndex().'.hoursRegime', 'pj');

        $this->putJson("/api/settings/members/{$this->member->id}/hours-regime", ['regime' => 'freelancer'], $this->headers($this->admin))
            ->assertStatus(422);
    }

    private function memberIndex(): int
    {
        $members = $this->getJson('/api/settings', $this->headers($this->admin))->json('members');

        return (int) array_search((string) $this->member->id, array_column($members, 'id'), true);
    }
}
