<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Policy;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * T020 — lançamento manual (US1): autorização por projeto, política vigente
 * (comentário obrigatório, limite diário, janela retroativa) e concorrência
 * otimista em edição/remoção.
 */
class EntryAuthorizationTest extends TestCase
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

    private function entryPayload(Project $project, array $overrides = []): array
    {
        return array_merge([
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'localDate' => now()->toDateString(),
            'durationSeconds' => 3600,
        ], $overrides);
    }

    public function test_authorized_member_can_create_a_manual_entry(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $response = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('a', 20)],
        ));

        $response->assertCreated()->assertJson(['durationSeconds' => 3600, 'source' => 'manual']);
        $this->assertDatabaseHas('time_entries', [
            'tenant_id' => $tenant->id,
            'member_id' => $member->id,
            'duration_seconds' => 3600,
        ]);
    }

    public function test_member_without_role_assignment_is_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();

        $response = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('b', 20)],
        ));

        $response->assertStatus(403);
        $this->assertSame(0, TimeEntry::query()->count());
    }

    public function test_disabled_project_denies_new_entries_even_with_role_assignment(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        $project->update(['is_enabled' => false]);

        $response = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('c', 20)],
        ));

        $response->assertStatus(403);
    }

    public function test_comment_required_policy_rejects_entry_without_note(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        Policy::factory()->for($tenant)->create(['comment_required' => true]);

        $response = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('d', 20)],
        ));

        $response->assertStatus(422);
    }

    public function test_comment_required_policy_accepts_entry_with_note(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        Policy::factory()->for($tenant)->create(['comment_required' => true]);

        $response = $this->postJson('/api/entries', $this->entryPayload($project, ['note' => 'Reunião de planejamento']), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('e', 20)],
        ));

        $response->assertCreated();
    }

    public function test_daily_limit_is_enforced_across_entries_for_the_same_date(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        Policy::factory()->for($tenant)->create(['daily_limit_hours' => 8]);

        $this->postJson('/api/entries', $this->entryPayload($project, ['durationSeconds' => 7 * 3600]), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('f', 20)],
        ))->assertCreated();

        $response = $this->postJson('/api/entries', $this->entryPayload($project, ['durationSeconds' => 2 * 3600]), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('g', 20)],
        ));

        $response->assertStatus(422);
    }

    public function test_backdated_entry_beyond_retroactive_window_is_rejected(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();
        Policy::factory()->for($tenant)->create(['retroactive_window_days' => 5]);

        $response = $this->postJson('/api/entries', $this->entryPayload($project, [
            'localDate' => now('America/Sao_Paulo')->subDays(10)->toDateString(),
        ]), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('h', 20)],
        ));

        $response->assertStatus(422);
    }

    public function test_updating_an_entry_with_stale_revision_is_rejected(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $created = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('i', 20)],
        ))->assertCreated();

        $entryId = $created->json('id');

        $response = $this->patchJson("/api/entries/{$entryId}", ['durationSeconds' => 1800], array_merge(
            $this->authHeader($tenant, $member),
            ['If-Match' => 99],
        ));

        $response->assertStatus(409);
    }

    public function test_updating_an_entry_with_correct_revision_succeeds_and_bumps_revision(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $created = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('j', 20)],
        ))->assertCreated();

        $entryId = $created->json('id');

        $response = $this->patchJson("/api/entries/{$entryId}", ['durationSeconds' => 1800], array_merge(
            $this->authHeader($tenant, $member),
            ['If-Match' => 1],
        ));

        $response->assertOk()->assertJson(['durationSeconds' => 1800, 'revision' => 2]);
    }

    public function test_deleting_an_entry_is_a_soft_delete(): void
    {
        [$tenant, $project, $member] = $this->setUpAuthorizedMember();

        $created = $this->postJson('/api/entries', $this->entryPayload($project), array_merge(
            $this->authHeader($tenant, $member),
            ['Idempotency-Key' => str_repeat('k', 20)],
        ))->assertCreated();

        $entryId = $created->json('id');

        $this->deleteJson("/api/entries/{$entryId}", [], $this->authHeader($tenant, $member))
            ->assertNoContent();

        $this->assertSoftDeleted('time_entries', ['id' => $entryId]);
    }
}
