<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Horário (início/fim) opcional no lançamento manual e na edição: o fim é
 * início + duração, em hora local; não pode passar da meia-noite.
 */
class EntryTimeOfDayTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Project $project;

    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::factory()->create(['default_timezone' => 'America/Sao_Paulo']);
        $this->project = Project::factory()->for($this->tenant)->create();
        $this->member = Member::factory()->for($this->tenant)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $this->tenant->id,
            'member_id' => $this->member->id,
            'project_id' => $this->project->id,
            'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        $this->travelTo('2026-09-30 15:00:00');
    }

    private function headers(array $extra = []): array
    {
        $session = app(SessionTokenService::class)->issue($this->tenant->id, $this->member->id);

        return ['Authorization' => "Bearer {$session->token}", 'Idempotency-Key' => (string) Str::uuid()] + $extra;
    }

    private function create(array $overrides = [])
    {
        return $this->postJson('/api/entries', array_merge([
            'projectId' => $this->project->devops_project_id,
            'projectName' => $this->project->devops_project_name,
            'workItemId' => 42,
            'localDate' => '2026-09-30',
            'durationSeconds' => 5400,
        ], $overrides), $this->headers());
    }

    private function patchEntry(TimeEntry $entry, array $changes)
    {
        return $this->patchJson("/api/entries/{$entry->id}", $changes, $this->headers(['If-Match' => (string) $entry->revision]));
    }

    private function utc(?object $value): ?string
    {
        return $value?->copy()->utc()->format('Y-m-d H:i:s');
    }

    public function test_a_manual_entry_can_carry_a_start_time_and_the_end_is_start_plus_duration(): void
    {
        $response = $this->create(['startTime' => '09:00'])->assertCreated();

        $response->assertJson(['startTime' => '09:00', 'endTime' => '10:30']);
        $entry = TimeEntry::query()->findOrFail($response->json('id'));
        // 09:00 em America/Sao_Paulo (UTC-3) = 12:00 UTC.
        $this->assertSame('2026-09-30 12:00:00', $this->utc($entry->started_at_utc));
        $this->assertSame('2026-09-30 13:30:00', $this->utc($entry->ended_at_utc));
    }

    public function test_without_a_start_time_there_is_no_time_of_day(): void
    {
        $response = $this->create()->assertCreated();

        $response->assertJson(['startTime' => null, 'endTime' => null]);
        $this->assertNull(TimeEntry::query()->findOrFail($response->json('id'))->started_at_utc);
    }

    public function test_the_entry_cannot_run_past_midnight_but_may_end_exactly_at_it(): void
    {
        $this->create(['startTime' => '22:30', 'durationSeconds' => 7200])
            ->assertStatus(422)->assertJsonValidationErrors('startTime');
        $this->assertSame(0, TimeEntry::query()->count());

        $this->create(['startTime' => '22:00', 'durationSeconds' => 7200])
            ->assertCreated()->assertJson(['startTime' => '22:00', 'endTime' => '00:00']);
    }

    public function test_the_start_time_format_is_validated(): void
    {
        $this->create(['startTime' => '9h'])->assertStatus(422)->assertJsonValidationErrors('startTime');
        $this->create(['startTime' => '25:00'])->assertStatus(422)->assertJsonValidationErrors('startTime');
    }

    public function test_editing_can_move_the_start_and_the_end_follows(): void
    {
        $entry = TimeEntry::query()->findOrFail($this->create(['startTime' => '09:00'])->json('id'));

        $this->patchEntry($entry, ['startTime' => '14:00'])->assertOk()->assertJson(['startTime' => '14:00', 'endTime' => '15:30']);
    }

    public function test_changing_the_duration_keeps_the_start_and_moves_the_end(): void
    {
        $entry = TimeEntry::query()->findOrFail($this->create(['startTime' => '09:00'])->json('id'));

        $this->patchEntry($entry, ['durationSeconds' => 3600])->assertOk()->assertJson(['startTime' => '09:00', 'endTime' => '10:00']);
    }

    public function test_an_entry_without_time_stays_without_time_when_the_duration_changes(): void
    {
        $entry = TimeEntry::query()->findOrFail($this->create()->json('id'));

        $this->patchEntry($entry, ['durationSeconds' => 3600])->assertOk()->assertJson(['startTime' => null, 'endTime' => null]);
    }

    public function test_a_start_time_can_be_added_later_and_cleared(): void
    {
        $entry = TimeEntry::query()->findOrFail($this->create()->json('id'));

        $added = $this->patchEntry($entry, ['startTime' => '08:15'])->assertOk()->assertJson(['startTime' => '08:15', 'endTime' => '09:45']);

        $entry->refresh();
        $this->patchEntry($entry, ['startTime' => null])->assertOk()->assertJson(['startTime' => null, 'endTime' => null]);
        $this->assertSame(2, $added->json('revision'));
    }

    public function test_an_edit_that_would_pass_midnight_is_refused_and_changes_nothing(): void
    {
        $entry = TimeEntry::query()->findOrFail($this->create(['startTime' => '22:30', 'durationSeconds' => 1800])->json('id'));

        $this->patchEntry($entry, ['durationSeconds' => 7200])->assertStatus(422)->assertJsonValidationErrors('startTime');

        $entry->refresh();
        $this->assertSame(1800, $entry->duration_seconds);
        $this->assertSame(1, $entry->revision);
        $this->assertSame('22:30', $entry->localStartTime());
    }

    public function test_the_week_view_shows_the_times(): void
    {
        $this->create(['startTime' => '09:00'])->assertCreated();

        $entries = $this->getJson('/api/me/weeks/2026-09-28', $this->headers())->assertOk()->json('entries');

        $this->assertSame(['09:00', '10:30'], [$entries[0]['startTime'], $entries[0]['endTime']]);
    }

    public function test_the_report_shows_the_manual_times_too(): void
    {
        $admin = Member::factory()->for($this->tenant)->create();
        RoleAssignment::factory()->create(['tenant_id' => $this->tenant->id, 'member_id' => $admin->id, 'project_id' => null, 'role' => RoleAssignment::ROLE_ADMIN]);
        $this->create(['startTime' => '09:00'])->assertCreated();

        $session = app(SessionTokenService::class)->issue($this->tenant->id, $admin->id);
        $rows = $this->getJson('/api/reports/time/detail?from=2026-09-28&to=2026-10-04', ['Authorization' => "Bearer {$session->token}"])
            ->assertOk()->json('rows');

        $this->assertSame(['09:00', '10:30'], [$rows[0]['startTime'], $rows[0]['endTime']]);
    }
}
