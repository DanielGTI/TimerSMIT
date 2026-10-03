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
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Duração mínima do lançamento manual, definida pelo administrador nas
 * regras de lançamento. Padrão: 1 minuto (sem mínimo). A correção feita pelo
 * administrador no relatório não passa por ela.
 */
class MinDurationTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-02 15:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create();
        $this->admin = Member::factory()->for($this->tenant)->create();

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
    }

    private function headers(?Member $as = null, array $extra = []): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, ($as ?? $this->member)->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()] + $extra;
    }

    private function create(int $minutes)
    {
        return $this->postJson('/api/entries', [
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-10-02',
            'durationSeconds' => $minutes * 60,
        ], $this->headers());
    }

    private function setMinimum(?int $minutes)
    {
        $policy = ['durationIncrementMinutes' => 1, 'dailyLimitHours' => 24, 'retroactiveWindowDays' => 30, 'commentRequired' => false];
        if ($minutes !== null) {
            $policy['minDurationMinutes'] = $minutes;
        }

        return $this->putJson('/api/settings/policy', $policy, $this->headers($this->admin));
    }

    public function test_without_a_minimum_any_duration_is_accepted(): void
    {
        $this->getJson('/api/me', $this->headers())->assertJsonPath('policy.minDurationMinutes', 1);
        $this->create(1)->assertCreated();
        $this->create(20)->assertCreated();
    }

    public function test_the_admin_sets_a_minimum_and_shorter_manual_entries_are_refused(): void
    {
        $this->setMinimum(15)->assertOk()->assertJsonPath('policy.minDurationMinutes', 15);
        $this->getJson('/api/me', $this->headers())->assertJsonPath('policy.minDurationMinutes', 15);

        $this->create(10)
            ->assertUnprocessable()
            ->assertJsonPath('errors.durationSeconds.0', 'A duração mínima de um lançamento é de 15 minutos.');
        $entry = TimeEntry::query()->findOrFail($this->create(15)->assertCreated()->json('id'));

        // Editar para menos que o mínimo também é recusado.
        $this->patchJson("/api/entries/{$entry->id}", ['durationSeconds' => 300], $this->headers(extra: ['If-Match' => '1']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('durationSeconds');

        // O administrador, corrigindo pelo relatório, não fica preso ao mínimo.
        $this->patchJson("/api/admin/entries/{$entry->id}", ['durationSeconds' => 300], $this->headers($this->admin, ['If-Match' => '1']))
            ->assertOk()
            ->assertJsonPath('durationSeconds', 300);

        // Salvar as regras sem o campo (extensão antiga) mantém o mínimo.
        $this->setMinimum(null)->assertOk()->assertJsonPath('policy.minDurationMinutes', 15);
        $this->assertSame(15, Policy::query()->orderByDesc('version')->firstOrFail()->min_duration_minutes);

        $this->setMinimum(0)->assertUnprocessable()->assertJsonValidationErrors('minDurationMinutes');
    }
}
