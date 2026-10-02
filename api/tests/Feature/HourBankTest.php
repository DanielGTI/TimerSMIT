<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\HourBankMovement;
use App\Models\Member;
use App\Models\OvertimeRule;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Banco de horas (Fase 2): créditos das horas classificadas como banco,
 * folgas/pagamentos/ajustes do administrador, saldo sem cobertura recusado
 * e vencimento.
 *
 * Sábado 03/10/2026, 4h de relógio × 1,5 = 6h no banco, vencendo em 03/04/2027.
 */
class HourBankTest extends TestCase
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
            'effective_from' => now(),
        ]);
    }

    private function headers(?Member $as = null, string $key = 'bank-key-00000000001'): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => $key];
    }

    private function saturday(int $minutes = 240): TimeEntry
    {
        return TimeEntry::factory()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'local_date' => '2026-10-03',
            'timezone' => self::TZ,
            'duration_seconds' => $minutes * 60,
        ]);
    }

    /** Lança o sábado, aprova a semana, manda para o banco e avança para 06/10. */
    private function creditSaturday(): TimeEntry
    {
        $entry = $this->saturday();

        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers(key: 'submit-key-0000000001'))->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve'], $this->headers($this->admin, 'decide-key-0000000001'))
            ->assertOk();
        $this->classify($entry, 'bank')->assertOk();

        $this->travelTo('2026-10-06 10:00:00');

        return $entry;
    }

    private function classify(TimeEntry $entry, string $classification)
    {
        return $this->postJson('/api/additional-hours/classify', ['entryIds' => [$entry->id], 'classification' => $classification], $this->headers($this->admin));
    }

    private function move(array $payload, string $key = 'move-key-00000000001', ?Member $as = null)
    {
        return $this->postJson("/api/hour-bank/{$this->member->id}/movements", $payload + [
            'kind' => 'time_off',
            'localDate' => '2026-10-06',
            'note' => 'Folga combinada',
        ], $this->headers($as ?? $this->admin, $key));
    }

    public function test_hours_classified_as_bank_are_credits_with_an_expiry_date(): void
    {
        $entry = $this->creditSaturday();

        $this->getJson('/api/me/hour-bank', $this->headers())
            ->assertOk()
            ->assertJsonPath('member.id', (string) $this->member->id)
            ->assertJsonPath('validityMonths', 6)
            ->assertJsonPath('summary.balanceSeconds', 6 * self::H)
            ->assertJsonPath('summary.expiredSeconds', 0)
            ->assertJsonPath('events.0.type', 'credit')
            ->assertJsonPath('events.0.entryId', (string) $entry->id)
            ->assertJsonPath('events.0.date', '2026-10-03')
            ->assertJsonPath('events.0.seconds', 6 * self::H)
            ->assertJsonPath('events.0.additionalSeconds', 4 * self::H)
            ->assertJsonPath('events.0.expiresOn', '2027-04-03');
    }

    public function test_the_rule_can_credit_clock_hours_and_use_a_twelve_month_term(): void
    {
        $this->rule(['bank_weighted' => false, 'bank_validity_months' => 12], 2);
        $this->creditSaturday();

        $this->getJson('/api/me/hour-bank', $this->headers())
            ->assertJsonPath('summary.balanceSeconds', 4 * self::H)
            ->assertJsonPath('events.0.expiresOn', '2027-10-03');
    }

    public function test_the_admin_registers_a_day_off_and_cannot_go_beyond_the_balance(): void
    {
        $this->creditSaturday();

        $this->move(['seconds' => 4 * self::H])
            ->assertOk()
            ->assertJsonPath('summary.balanceSeconds', 2 * self::H)
            ->assertJsonPath('events.1.type', 'time_off')
            ->assertJsonPath('events.1.seconds', -4 * self::H)
            ->assertJsonPath('events.1.note', 'Folga combinada')
            ->assertJsonPath('events.1.createdBy', 'Daniel Admin');

        // Repetir o pedido (duplo clique) não lança de novo.
        $this->move(['seconds' => 4 * self::H])->assertOk()->assertJsonPath('summary.balanceSeconds', 2 * self::H);
        $this->assertSame(1, HourBankMovement::query()->count());

        $this->move(['seconds' => 3 * self::H], 'move-key-00000000002')
            ->assertStatus(409)
            ->assertJsonPath('message', 'Saldo insuficiente: em 06/10/2026 faltariam 01:00 no banco de horas.');

        $this->move(['seconds' => 1 * self::H, 'note' => '  '], 'move-key-00000000003')->assertStatus(422);
        $this->assertTrue(AuditEvent::query()->where('action', 'hour_bank.movement_created')->exists());
    }

    public function test_movement_dates_are_limited(): void
    {
        $this->creditSaturday();

        $this->move(['seconds' => self::H, 'kind' => 'payout', 'localDate' => '2026-10-07'], 'move-key-00000000001')->assertStatus(422);
        $this->move(['seconds' => self::H, 'localDate' => '2027-01-05'], 'move-key-00000000002')->assertStatus(422);
        // Folga agendada dentro de 90 dias pode.
        $this->move(['seconds' => self::H, 'localDate' => '2026-11-06'], 'move-key-00000000003')->assertOk();
    }

    public function test_bank_hours_already_used_cannot_be_reclassified_or_have_the_week_reopened(): void
    {
        $entry = $this->creditSaturday();
        $movementId = $this->move(['seconds' => 4 * self::H])->assertOk()->json('events.1.movementId');

        $this->classify($entry, 'overtime')->assertStatus(409);
        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/reopen", ['reason' => 'Faltou um lançamento'], $this->headers($this->admin, 'reopen-key-0000000001'))
            ->assertStatus(409);
        $this->assertSame('approved', $submission->fresh()->status);

        // Desfeita a folga, a reclassificação passa.
        $this->deleteJson("/api/hour-bank/movements/{$movementId}", [], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('summary.balanceSeconds', 6 * self::H);
        $this->assertTrue(AuditEvent::query()->where('action', 'hour_bank.movement_removed')->exists());
        $this->classify($entry, 'overtime')->assertOk();

        $this->getJson('/api/me/hour-bank', $this->headers())->assertJsonPath('summary.balanceSeconds', 0)->assertJsonCount(0, 'events');
    }

    public function test_unused_hours_expire_and_become_payable(): void
    {
        $this->creditSaturday();
        $this->move(['seconds' => 2 * self::H])->assertOk();

        $this->travelTo('2027-03-10 10:00:00');
        $this->getJson('/api/me/hour-bank', $this->headers())
            ->assertJsonPath('summary.expiringSoonSeconds', 4 * self::H)
            ->assertJsonPath('summary.nextExpiry', '2027-04-03');

        $this->travelTo('2027-04-05 10:00:00');
        $this->getJson('/api/me/hour-bank', $this->headers())
            ->assertJsonPath('summary.balanceSeconds', 0)
            ->assertJsonPath('summary.expiredSeconds', 4 * self::H)
            ->assertJsonPath('events.2.type', 'expiry')
            ->assertJsonPath('events.2.date', '2027-04-03')
            ->assertJsonPath('events.2.seconds', -4 * self::H)
            ->assertJsonPath('events.2.creditDate', '2026-10-03');

        $this->move(['seconds' => self::H, 'localDate' => '2027-04-05'], 'move-key-00000000002')->assertStatus(409);
    }

    public function test_adjustments_add_or_remove_hours_and_only_clt_receives_credit(): void
    {
        $this->move(['kind' => 'adjustment', 'direction' => 'credit', 'seconds' => 2 * self::H, 'note' => 'Saldo da planilha antiga', 'localDate' => '2026-09-30'])
            ->assertOk()
            ->assertJsonPath('summary.balanceSeconds', 2 * self::H)
            ->assertJsonPath('events.0.type', 'adjustment')
            ->assertJsonPath('events.0.expiresOn', '2027-03-30');

        $creditId = HourBankMovement::query()->firstOrFail()->id;
        $this->move(['kind' => 'adjustment', 'direction' => 'debit', 'seconds' => self::H, 'note' => 'Correção', 'localDate' => '2026-09-30'], 'move-key-00000000002')
            ->assertOk()
            ->assertJsonPath('summary.balanceSeconds', self::H);

        // O crédito já usado não pode ser desfeito.
        $this->deleteJson("/api/hour-bank/movements/{$creditId}", [], $this->headers($this->admin))->assertStatus(409);

        $this->member->update(['hours_regime' => Member::REGIME_PJ]);
        $this->move(['kind' => 'adjustment', 'direction' => 'credit', 'seconds' => self::H, 'note' => 'X', 'localDate' => '2026-09-30'], 'move-key-00000000003')
            ->assertStatus(422);
    }

    public function test_only_admins_see_everyone_and_launch_movements(): void
    {
        $this->creditSaturday();

        $this->getJson('/api/hour-bank', $this->headers())->assertForbidden();
        $this->getJson("/api/hour-bank/{$this->admin->id}", $this->headers())->assertForbidden();
        $this->move(['seconds' => self::H], as: $this->member)->assertForbidden();

        $members = collect($this->getJson('/api/hour-bank', $this->headers($this->admin))->assertOk()->json('members'));
        $this->assertSame(6 * self::H, $members->firstWhere('memberId', (string) $this->member->id)['summary']['balanceSeconds']);

        $this->getJson("/api/hour-bank/{$this->member->id}", $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('summary.balanceSeconds', 6 * self::H);
    }

    public function test_the_term_and_weighting_are_part_of_the_versioned_rules(): void
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
            ->assertJsonPath('overtime.bankValidityMonths', 6)
            ->assertJsonPath('overtime.bankWeighted', true);

        $this->putJson('/api/settings/overtime-rules', $payload + ['bankValidityMonths' => 12, 'bankWeighted' => false], $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('overtime.bankValidityMonths', 12)
            ->assertJsonPath('overtime.bankWeighted', false);

        // Sem os campos do banco, a nova versão mantém os da anterior.
        $this->putJson('/api/settings/overtime-rules', $payload, $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('overtime.bankValidityMonths', 12);

        $this->putJson('/api/settings/overtime-rules', $payload + ['bankValidityMonths' => 13], $this->headers($this->admin))->assertStatus(422);
    }
}
