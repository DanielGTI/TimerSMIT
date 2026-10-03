<?php

namespace Tests\Feature;

use App\Models\ApproverAssignment;
use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\OvertimeRequest;
use App\Models\OvertimeRule;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Models\WeeklySubmission;
use App\Services\SessionTokenService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fase 4: "Informar hora extra" e perfil de hora extra por pessoa.
 *
 *  - Pré-aprovada: hora extra sem pedido e sem aviso.
 *  - Padrão: lança; sem pedido aprovado, a hora fica "sujeita à aprovação".
 *  - Restrita: o trecho fora do expediente sem pedido aprovado vira "hora
 *    extra a confirmar" e só conta se o aprovador confirmar.
 *
 * Expediente 09:00–18:00; a semana é a de 05/10/2026 (segunda).
 */
class OvertimeRequestTest extends TestCase
{
    use RefreshDatabase;

    private const TZ = 'America/Sao_Paulo';

    private const WEEK = '2026-10-05';

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    private Member $approver;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Quarta, 07/10/2026, 09:00 em São Paulo.
        $this->travelTo('2026-10-07 12:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => self::TZ]);
        $this->project = Project::factory()->for($this->tenant)->create(['devops_project_name' => 'SARC']);
        $this->member = Member::factory()->for($this->tenant)->create(['display_name' => 'Ana']);
        $this->approver = Member::factory()->for($this->tenant)->create(['display_name' => 'Bruno Aprovador']);
        $this->admin = Member::factory()->for($this->tenant)->create(['display_name' => 'Daniel Admin']);

        foreach ([$this->member, $this->approver] as $person) {
            RoleAssignment::factory()->create([
                'tenant_id' => $this->tenant->id,
                'member_id' => $person->id,
                'project_id' => $this->project->id,
                'role' => RoleAssignment::ROLE_MEMBER,
            ]);
        }
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->admin->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);
        ApproverAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => null,
            'approver_id' => $this->approver->id,
        ]);

        OvertimeRule::query()->create([
            'tenant_id' => $this->tenant->id,
            'version' => 1,
            'enabled' => true,
            'require_time_of_day' => true,
            'effective_from' => now(),
        ]);
    }

    private function headers(?Member $as = null, array $extra = []): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()] + $extra;
    }

    private function profile(string $profile): void
    {
        $this->putJson("/api/settings/members/{$this->member->id}/overtime-profile", ['profile' => $profile], $this->headers($this->admin))
            ->assertOk();
    }

    private function inform(array $data = [])
    {
        return $this->postJson('/api/me/overtime', $data + [
            'dateFrom' => '2026-10-07',
            'secondsPerDay' => 3 * 3600,
            'startTime' => '18:00',
            'endTime' => '21:00',
            'reason' => 'Entrega do PIX em lote',
            'suggestedDestination' => 'bank',
        ], $this->headers());
    }

    private function create(string $date, string $start, int $minutes, array $extra = [])
    {
        return $this->postJson('/api/entries', $extra + [
            'projectId' => $this->project->devops_project_id,
            'projectName' => 'SARC',
            'workItemId' => 15647,
            'localDate' => $date,
            'startTime' => $start,
            'durationSeconds' => $minutes * 60,
            'note' => 'PIX',
        ], $this->headers());
    }

    private function decide(int|string $requestId, bool $approve, array $extra = [], ?Member $by = null)
    {
        return $this->postJson("/api/overtime/{$requestId}/decision", ['approve' => $approve] + $extra, $this->headers($by ?? $this->approver));
    }

    private function weekEntries(): array
    {
        return $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->assertOk()->json('entries');
    }

    // ---------- informar hora extra ----------

    public function test_the_person_informs_overtime_and_the_approver_decides_it(): void
    {
        $request = $this->inform()->assertCreated()
            ->assertJsonPath('status', 'pending')
            ->assertJsonPath('afterTheFact', false)
            ->assertJsonPath('suggestedDestination', 'bank')
            ->json();

        // Só o aprovador designado (ou administrador) decide.
        $outsider = Member::factory()->for($this->tenant)->create();
        $this->decide($request['id'], true, by: $outsider)->assertForbidden();
        $this->decide($request['id'], true, by: $this->member)->assertForbidden();

        $this->getJson('/api/overtime/pending', $this->headers($this->approver))
            ->assertOk()
            ->assertJsonCount(1)
            ->assertJsonPath('0.person.displayName', 'Ana')
            ->assertJsonPath('0.kind', 'request');
        $this->getJson('/api/overtime/pending', $this->headers($outsider))->assertOk()->assertJsonCount(0);

        // Recusar exige motivo; aprovar pode liberar menos do que o pedido.
        $this->decide($request['id'], false)->assertUnprocessable()->assertJsonValidationErrors('note');
        $this->decide($request['id'], true, ['approvedSecondsPerDay' => 2 * 3600, 'note' => 'Até 2h'])
            ->assertOk()
            ->assertJsonPath('status', 'approved')
            ->assertJsonPath('approvedSecondsPerDay', 7200)
            ->assertJsonPath('decidedBy', 'Bruno Aprovador');
        $this->decide($request['id'], true)->assertStatus(409);

        // 18:00–21:00: 2h cobertas pelo pedido, 1h sem pedido (sujeita à aprovação).
        $this->create('2026-10-07', '17:00', 240)->assertCreated();
        $coverage = $this->weekEntries()[0]['additional']['coverage'];
        $this->assertSame(['partial', 7200, 3600, 'bank'], [$coverage['kind'], $coverage['coveredSeconds'], $coverage['uncoveredSeconds'], $coverage['suggestedDestination']]);

        $this->getJson('/api/me/overtime', $this->headers())
            ->assertJsonPath('applies', true)
            ->assertJsonPath('profile', 'standard')
            ->assertJsonPath('items.0.status', 'approved');
        $this->assertTrue(AuditEvent::query()->where('action', 'overtime.request_decided')->exists());
    }

    public function test_informing_after_the_fact_cancelling_and_the_rules_of_the_request(): void
    {
        $this->inform(['dateFrom' => '2026-10-06', 'startTime' => null, 'endTime' => null])
            ->assertCreated()
            ->assertJsonPath('afterTheFact', true);

        $pending = $this->inform()->json('id');
        $this->postJson("/api/me/overtime/{$pending}/cancel", [], $this->headers())->assertOk()->assertJsonPath('status', 'cancelled');
        $this->postJson("/api/me/overtime/{$pending}/cancel", [], $this->headers())->assertStatus(409);

        $this->inform(['dateTo' => '2026-11-30'])->assertUnprocessable()->assertJsonValidationErrors('dateTo');
        $this->inform(['endTime' => null])->assertUnprocessable()->assertJsonValidationErrors('startTime');
        $this->inform(['reason' => ''])->assertUnprocessable()->assertJsonValidationErrors('reason');

        // PJ não tem hora extra: não informa.
        $this->member->update(['hours_regime' => Member::REGIME_PJ]);
        $this->inform()->assertUnprocessable()->assertJsonValidationErrors('dateFrom');
        $this->getJson('/api/me/overtime', $this->headers())->assertJsonPath('applies', false);
    }

    // ---------- perfis ----------

    public function test_only_admins_set_the_profile_and_the_preapproved_profile_needs_no_request(): void
    {
        $this->putJson("/api/settings/members/{$this->member->id}/overtime-profile", ['profile' => 'preapproved'], $this->headers($this->approver))
            ->assertForbidden();
        $this->putJson("/api/settings/members/{$this->member->id}/overtime-profile", ['profile' => 'vip'], $this->headers($this->admin))
            ->assertUnprocessable();

        $this->profile('preapproved');
        $this->getJson('/api/settings', $this->headers($this->admin))->assertJsonPath('members.0.overtimeProfile', 'preapproved');
        $this->getJson('/api/me', $this->headers())
            ->assertJsonPath('overtimeProfile', 'preapproved')
            ->assertJsonPath('isAdmin', false);
        $this->getJson('/api/me', $this->headers($this->admin))->assertJsonPath('isAdmin', true);
        $this->assertTrue(AuditEvent::query()->where('action', 'settings.overtime_profile_updated')->exists());

        $this->getJson('/api/me/overtime-check?date=2026-10-07&startTime=18:00&durationSeconds=7200', $this->headers())
            ->assertJsonPath('additionalSeconds', 7200)
            ->assertJsonPath('uncoveredSeconds', 0)
            ->assertJsonPath('pending', []);

        $this->create('2026-10-07', '18:00', 120)->assertCreated();
        $this->assertSame('preapproved', $this->weekEntries()[0]['additional']['coverage']['kind']);
    }

    public function test_the_standard_profile_launches_and_the_check_tells_what_is_subject_to_approval(): void
    {
        $this->getJson('/api/me/overtime-check?date=2026-10-07&startTime=17:00&durationSeconds=9000', $this->headers())
            ->assertOk()
            ->assertJsonPath('applies', true)
            ->assertJsonPath('profile', 'standard')
            ->assertJsonPath('additionalSeconds', 5400)
            ->assertJsonPath('uncoveredSeconds', 5400)
            ->assertJsonPath('pending', []);

        $this->create('2026-10-07', '17:00', 150)->assertCreated()->assertJsonMissingPath('pendingOvertime');
        $this->assertSame('none', $this->weekEntries()[0]['additional']['coverage']['kind']);
    }

    // ---------- perfil restrito ----------

    public function test_the_restricted_profile_turns_the_extra_part_into_overtime_to_confirm(): void
    {
        $this->profile('restricted');

        $this->getJson('/api/me/overtime-check?date=2026-10-07&startTime=17:00&durationSeconds=9000', $this->headers())
            ->assertJsonPath('entries', [['startTime' => '17:00', 'endTime' => '18:00', 'seconds' => 3600]])
            ->assertJsonPath('pending', [['startTime' => '18:00', 'endTime' => '19:30', 'seconds' => 5400]]);

        // Sem motivo e sem ciência, nada é gravado.
        $this->create('2026-10-07', '17:00', 150)->assertUnprocessable()->assertJsonValidationErrors('overtimeReason');
        $this->create('2026-10-07', '17:00', 150, ['overtimeReason' => 'Deploy'])->assertUnprocessable();
        $this->assertSame(0, TimeEntry::query()->count());

        $response = $this->create('2026-10-07', '17:00', 150, ['overtimeReason' => 'Deploy', 'overtimeAcknowledged' => true])
            ->assertCreated()
            ->assertJsonPath('startTime', '17:00')
            ->assertJsonPath('endTime', '18:00')
            ->assertJsonPath('pendingOvertime.0.startTime', '18:00')
            ->assertJsonPath('pendingOvertime.0.endTime', '19:30')
            ->assertJsonPath('pendingOvertime.0.status', 'pending');

        $this->assertSame(3600, (int) TimeEntry::query()->sum('duration_seconds'));
        $week = $this->getJson('/api/me/weeks/'.self::WEEK, $this->headers())->json();
        $this->assertSame(3600, $week['totalSeconds']);
        $this->assertSame('restricted', $week['overtimeProfile']);
        $this->assertSame('Deploy', $week['overtimeConfirmations'][0]['reason']);

        // A semana não pode ser aprovada com hora a confirmar pendente.
        $this->postJson('/api/me/weeks/'.self::WEEK.'/submit', [], $this->headers())->assertOk();
        $submission = WeeklySubmission::query()->firstOrFail();
        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve'], $this->headers($this->approver))
            ->assertStatus(409);

        // Confirmada, vira lançamento (já autorizado); aí a semana é aprovada.
        $confirmation = $response->json('pendingOvertime.0.id');
        $this->getJson('/api/overtime/pending', $this->headers($this->approver))->assertJsonPath('0.weekStatus', 'submitted');
        $this->decide($confirmation, true)->assertOk()->assertJsonPath('status', 'approved');

        $entries = collect($this->weekEntries());
        $this->assertSame(9000, $entries->sum('durationSeconds'));
        $confirmed = $entries->firstWhere('startTime', '18:00');
        $this->assertSame('19:30', $confirmed['endTime']);
        $this->assertSame('confirmed', $confirmed['additional']['coverage']['kind']);

        $this->postJson("/api/approvals/{$submission->id}/decision", ['decision' => 'approve'], $this->headers($this->approver))->assertOk();
    }

    public function test_a_refused_overtime_never_counts_and_shows_in_the_closing(): void
    {
        $this->profile('restricted');

        // Sábado: o lançamento inteiro é hora extra; nada entra como lançamento.
        $response = $this->create('2026-10-10', '10:00', 120, ['overtimeReason' => 'Plantão', 'overtimeAcknowledged' => true])
            ->assertCreated()
            ->assertJsonPath('id', null)
            ->assertJsonPath('entries', []);
        $this->assertSame(0, TimeEntry::query()->count());

        $id = $response->json('pendingOvertime.0.id');
        $this->decide($id, false, ['note' => 'Não combinado'])->assertOk()->assertJsonPath('status', 'rejected');
        $this->assertSame(0, TimeEntry::query()->count());

        $this->getJson('/api/additional-hours/closing?month=2026-10', $this->headers($this->admin))
            ->assertOk()
            ->assertJsonPath('members.0.memberName', 'Ana')
            ->assertJsonPath('members.0.overtime.refusedSeconds', 7200);
    }

    public function test_an_approved_request_lets_the_restricted_profile_launch_up_to_the_limit(): void
    {
        $this->profile('restricted');
        $request = $this->inform(['secondsPerDay' => 3600])->json('id');
        $this->decide($request, true)->assertOk();

        // 17:00–19:30: 18:00–19:00 coberto pelo pedido; só 19:00–19:30 fica a confirmar.
        $this->create('2026-10-07', '17:00', 150, ['overtimeReason' => 'Passou do combinado', 'overtimeAcknowledged' => true])
            ->assertCreated()
            ->assertJsonPath('startTime', '17:00')
            ->assertJsonPath('endTime', '19:00')
            ->assertJsonPath('pendingOvertime.0.startTime', '19:00')
            ->assertJsonPath('pendingOvertime.0.endTime', '19:30');

        // Inteiramente dentro do pedido: entra direto, sem motivo.
        $this->create('2026-10-08', '18:00', 60)->assertUnprocessable();
        $this->decide($this->inform(['dateFrom' => '2026-10-08', 'secondsPerDay' => 3600])->json('id'), true);
        $this->create('2026-10-08', '18:00', 60)->assertCreated()->assertJsonMissingPath('pendingOvertime');
    }

    public function test_the_restricted_profile_cannot_stretch_an_entry_past_the_workday(): void
    {
        $this->profile('restricted');
        $entry = $this->create('2026-10-07', '16:00', 120)->assertCreated()->json();

        $this->patchJson("/api/entries/{$entry['id']}", ['durationSeconds' => 4 * 3600], $this->headers(extra: ['If-Match' => '1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('startTime');
        $this->patchJson("/api/entries/{$entry['id']}", ['note' => 'ok'], $this->headers(extra: ['If-Match' => '1']))->assertOk();
    }

    public function test_the_timer_of_the_restricted_profile_asks_the_reason_and_splits(): void
    {
        $this->profile('restricted');
        $timer = TimerSession::query()->create([
            'tenant_id' => $this->tenant->id,
            'project_id' => $this->project->id,
            'member_id' => $this->member->id,
            'devops_work_item_id' => 15647,
            'status' => TimerSession::STATUS_ACTIVE,
            'started_at_utc' => CarbonImmutable::parse('2026-10-07 17:00', self::TZ)->utc(),
            'idempotency_key' => (string) Str::uuid(),
        ]);
        $this->travelTo(CarbonImmutable::parse('2026-10-07 19:00', self::TZ)->utc());

        $this->postJson('/api/me/timer/stop', ['timerId' => $timer->id], $this->headers())
            ->assertUnprocessable()
            ->assertJsonValidationErrors('overtimeReason');
        $this->assertSame(TimerSession::STATUS_ACTIVE, $timer->refresh()->status);

        $this->postJson('/api/me/timer/stop', ['timerId' => $timer->id, 'overtimeReason' => 'Incidente', 'overtimeAcknowledged' => true], $this->headers())
            ->assertOk()
            ->assertHeader('X-Pending-Overtime', '1')
            ->assertJsonCount(1)
            ->assertJsonPath('0.startTime', '17:00')
            ->assertJsonPath('0.endTime', '18:00');

        $confirmation = OvertimeRequest::query()->firstOrFail();
        $this->assertSame(['18:00', '19:00', 3600, 'timer'], [$confirmation->start_time, $confirmation->end_time, $confirmation->seconds_per_day, $confirmation->source]);
    }
}
