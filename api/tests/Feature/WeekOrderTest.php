<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Folha semanal: os lançamentos aparecem por dia e, no dia, pelo horário de início. */
class WeekOrderTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-06 18:00:00');

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create();

        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);
    }

    private function headers(): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, $this->member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()];
    }

    private function log(string $date, ?string $start, int $minutes, int $workItem = 42): void
    {
        $this->postJson('/api/entries', array_filter([
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => $workItem,
            'localDate' => $date,
            'startTime' => $start,
            'durationSeconds' => $minutes * 60,
        ], fn ($value) => $value !== null), $this->headers())->assertCreated();
    }

    public function test_entries_come_by_day_then_by_start_time_not_by_creation_order(): void
    {
        // Lançado primeiro (id menor), mas começa depois; o retroativo das 10h entra por último.
        $this->log('2026-10-05', '12:00', 60);
        $this->log('2026-10-05', '14:30', 30);
        $this->log('2026-10-05', '10:00', 60);
        $this->log('2026-10-05', null, 15, 43);
        $this->log('2026-10-06', '13:00', 30);
        $this->log('2026-10-06', '07:00', 30);

        $week = $this->getJson('/api/me/weeks/2026-10-05', $this->headers())->assertOk();

        $this->assertSame(
            [
                ['2026-10-05', '10:00'],
                ['2026-10-05', '12:00'],
                ['2026-10-05', '14:30'],
                ['2026-10-05', null],
                ['2026-10-06', '07:00'],
                ['2026-10-06', '13:00'],
            ],
            collect($week->json('entries'))->map(fn (array $entry) => [$entry['localDate'], $entry['startTime']])->all(),
        );
    }
}
