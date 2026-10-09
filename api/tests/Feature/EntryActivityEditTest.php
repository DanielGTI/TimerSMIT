<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** A pessoa corrige a atividade do próprio lançamento (folha semanal → Editar). */
class EntryActivityEditTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Member $member;

    private TimeEntry $entry;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02 15:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create();

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        $response = $this->postJson('/api/entries', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-10-02',
            'durationSeconds' => 1800,
        ], $this->headers());

        $this->entry = TimeEntry::query()->findOrFail($response->assertCreated()->json('id'));
    }

    private function headers(array $extra = []): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, $this->member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()] + $extra;
    }

    public function test_the_member_changes_and_clears_the_activity_of_their_entry(): void
    {
        $dev = ActivityType::factory()->for($this->tenant)->create(['name' => 'Desenvolvimento']);

        $this->patchJson("/api/entries/{$this->entry->id}", ['activityTypeId' => $dev->id], $this->headers(['If-Match' => '1']))
            ->assertOk()
            ->assertJsonPath('activityTypeId', (string) $dev->id)
            ->assertJsonPath('revision', 2);

        $this->patchJson("/api/entries/{$this->entry->id}", ['activityTypeId' => null], $this->headers(['If-Match' => '2']))
            ->assertOk()
            ->assertJsonPath('activityTypeId', null);

        // Sem o campo (extensão antiga), a atividade não muda.
        $this->patchJson("/api/entries/{$this->entry->id}", ['activityTypeId' => $dev->id], $this->headers(['If-Match' => '3']))->assertOk();
        $this->patchJson("/api/entries/{$this->entry->id}", ['note' => 'ajuste'], $this->headers(['If-Match' => '4']))
            ->assertOk()
            ->assertJsonPath('activityTypeId', (string) $dev->id);
    }

    public function test_an_activity_that_is_disabled_or_from_another_organization_is_refused(): void
    {
        $disabled = ActivityType::factory()->for($this->tenant)->create(['is_enabled' => false]);
        $foreign = ActivityType::factory()->for(Tenant::factory()->create())->create();

        foreach ([$disabled, $foreign] as $type) {
            $this->patchJson("/api/entries/{$this->entry->id}", ['activityTypeId' => $type->id], $this->headers(['If-Match' => '1']))
                ->assertUnprocessable()
                ->assertJsonValidationErrors('activityTypeId');
        }
    }
}
