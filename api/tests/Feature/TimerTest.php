<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\TimerSession;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * T020 — timer (US1): repetição idempotente, corrida de um timer ativo por
 * membro, fechamento com fatia de meia-noite e revogação de acesso.
 */
class TimerTest extends TestCase
{
    use RefreshDatabase;

    private function authHeader(Tenant $tenant, Member $member): array
    {
        $session = app(SessionTokenService::class)->issue($tenant->id, $member->id);

        return ['Authorization' => "Bearer {$session->token}"];
    }

    private function setUpAuthorizedMember(): array
    {
        $tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'project_id' => $project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        return [$tenant, $project, $member];
    }

    public function test_get_timer_returns_literal_null_when_none_is_active(): void
    {
        [$tenant, , $member] = $this->setUpAuthorizedMember();

        $response = $this->getJson('/api/me/timer', $this->authHeader($tenant, $member));

        // `{}` seria truthy no cliente e apareceria como "timer ativo".
        $response->assertOk();
        $this->assertSame('null', $response->getContent());
    }

    public function test_get_timer_returns_the_active_timer(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('z', 20)]))
            ->assertCreated();

        $this->getJson('/api/me/timer', $this->authHeader($tenant, $member))
            ->assertOk()
            ->assertJson(['workItemId' => 42, 'status' => TimerSession::STATUS_ACTIVE]);
    }

    public function test_starts_a_timer_for_an_authorized_member(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $response = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('a', 20)]));

        $response->assertCreated()->assertJson([
            'workItemId' => 42,
            'status' => TimerSession::STATUS_ACTIVE,
        ]);

        $this->assertDatabaseHas('timer_sessions', [
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'devops_work_item_id' => 42,
            'status' => TimerSession::STATUS_ACTIVE,
        ]);
    }

    public function test_repeating_start_with_same_idempotency_key_returns_the_same_timer(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $headers = array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('b', 20)]);

        $first = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], $headers);

        $second = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], $headers);

        $first->assertCreated();
        $second->assertCreated();
        $this->assertSame($first->json('id'), $second->json('id'));
        $this->assertSame(1, TimerSession::query()->count());
    }

    public function test_cannot_start_a_second_timer_while_one_is_already_active(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('c', 20)]))
            ->assertCreated();

        $second = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 43,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('d', 20)]));

        $second->assertStatus(409);
        $this->assertSame(1, TimerSession::query()->where('status', TimerSession::STATUS_ACTIVE)->count());
    }

    public function test_stopping_a_timer_that_crosses_midnight_splits_into_two_entries(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $headers = $this->authHeader($tenant, $member);

        $start = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($headers, ['Idempotency-Key' => str_repeat('e', 20)]))->assertCreated();

        $timer = TimerSession::query()->findOrFail($start->json('id'));
        // 23:00 -> 01:00 America/Sao_Paulo (UTC-3): atravessa meia-noite local.
        $timer->update([
            'started_at_utc' => '2026-10-01 02:00:00',
        ]);

        // Congela o "agora" do fechamento para tornar o teste determinístico.
        $this->travelTo(Carbon::parse('2026-10-01 04:00:00', 'UTC'));

        // Sessão nova: a anterior expirou no salto de ~1 dia do relógio.
        $stop = $this->postJson('/api/me/timer/stop', [
            'timerId' => $timer->id,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('f', 20)]));

        $stop->assertOk();
        $entries = $stop->json();
        $this->assertCount(2, $entries);

        $totalSeconds = array_sum(array_column($entries, 'durationSeconds'));
        $this->assertSame(7200, $totalSeconds);

        $dates = array_column($entries, 'localDate');
        sort($dates);
        $this->assertSame(['2026-09-30', '2026-10-01'], $dates);

        // Cada fatia guarda o horário real (UTC) e uma começa onde a outra termina.
        $slices = TimeEntry::query()->where('timer_session_id', $timer->id)->orderBy('local_date')->get();
        $this->assertSame('2026-10-01 02:00:00', $slices[0]->started_at_utc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 03:00:00', $slices[0]->ended_at_utc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 03:00:00', $slices[1]->started_at_utc->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-01 04:00:00', $slices[1]->ended_at_utc->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_iteration_path_sent_with_the_timer_is_kept_with_the_work_item_snapshot(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'title' => 'Implementar PIX',
            'workItemType' => 'Task',
            'iterationPath' => 'SARC\Sprint 12',
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('p', 20)]))->assertCreated();

        $this->assertDatabaseHas('work_item_snapshots', [
            'tenant_id' => $tenant->id,
            'devops_work_item_id' => 42,
            'title' => 'Implementar PIX',
            'iteration_path' => 'SARC\Sprint 12',
        ]);
    }

    public function test_stopping_an_already_stopped_timer_is_rejected(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $headers = $this->authHeader($tenant, $member);

        $start = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($headers, ['Idempotency-Key' => str_repeat('g', 20)]))->assertCreated();

        $timerId = $start->json('id');

        $this->postJson('/api/me/timer/stop', ['timerId' => $timerId], array_merge($headers, ['Idempotency-Key' => str_repeat('h', 20)]))
            ->assertOk();

        $this->postJson('/api/me/timer/stop', ['timerId' => $timerId], array_merge($headers, ['Idempotency-Key' => str_repeat('i', 20)]))
            ->assertStatus(409);
    }

    public function test_member_without_role_assignment_cannot_start_a_timer(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();

        $response = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('j', 20)]));

        $response->assertStatus(403);
        $this->assertSame(0, TimerSession::query()->count());
    }

    public function test_revoking_role_assignment_blocks_new_timers_on_that_project(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        RoleAssignment::query()->where('member_id', $member->id)->delete();

        $response = $this->postJson('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], array_merge($this->authHeader($tenant, $member), ['Idempotency-Key' => str_repeat('k', 20)]));

        $response->assertStatus(403);
    }
}
