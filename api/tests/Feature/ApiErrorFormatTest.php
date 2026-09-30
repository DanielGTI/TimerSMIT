<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * O fetch do navegador não envia Accept: application/json — a API precisa
 * responder JSON mesmo assim, para a extensão mostrar o motivo do erro.
 */
class ApiErrorFormatTest extends TestCase
{
    use RefreshDatabase;

    private function headers(Tenant $tenant, Member $member): array
    {
        $session = app(SessionTokenService::class)->issue($tenant->id, $member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Accept' => '*/*', 'Idempotency-Key' => str_repeat('a', 20)];
    }

    public function test_forbidden_is_json_with_an_actionable_message_even_without_accept_json(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();

        $response = $this->post('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], $this->headers($tenant, $member));

        $response->assertStatus(403)->assertHeader('Content-Type', 'application/json');
        $this->assertStringContainsString('peça a um administrador', $response->json('message'));
    }

    public function test_the_forbidden_message_does_not_reveal_why_access_was_denied(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create(['is_enabled' => false]);
        $member = Member::factory()->for($tenant)->create();
        RoleAssignment::factory()->create(['tenant_id' => $tenant->id, 'member_id' => $member->id, 'project_id' => $project->id]);

        $response = $this->post('/api/me/timer', [
            'projectId' => $project->devops_project_id,
            'projectName' => $project->devops_project_name,
            'workItemId' => 42,
        ], $this->headers($tenant, $member));

        $response->assertStatus(403);
        $this->assertStringNotContainsString('desabilitado', $response->getContent());
    }

    public function test_unknown_api_route_is_json_404(): void
    {
        $this->get('/api/nao-existe', ['Accept' => '*/*'])
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }

    public function test_stopping_a_timer_that_is_not_yours_is_json_404(): void
    {
        $tenant = Tenant::factory()->create();
        $member = Member::factory()->for($tenant)->create();

        $this->post('/api/me/timer/stop', ['timerId' => 999], $this->headers($tenant, $member))
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json');
    }
}
