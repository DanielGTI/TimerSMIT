<?php

namespace Tests\Feature;

use App\Http\Middleware\LogSlowOrFailedRequests;
use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * T041 — operação: health check, métrica por log e logs sem token.
 */
class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = tempnam(sys_get_temp_dir(), 'timersmit-log-');
        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logFile,
            'logging.channels.single.level' => 'debug',
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    private function logContents(): string
    {
        return (string) file_get_contents($this->logFile);
    }

    public function test_health_reports_ok_without_authentication_and_without_details(): void
    {
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
    }

    public function test_health_reports_unavailable_when_the_database_is_down(): void
    {
        DB::shouldReceive('select')->once()->andThrow(new RuntimeException('connection refused to db-host:5432 password=segredo'));

        $response = $this->getJson('/api/health');

        // Nem a resposta nem o log carregam a mensagem do driver (host, senha).
        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
        $this->assertStringContainsString('api.health.database_unreachable', $this->logContents());
        $this->assertStringNotContainsString('segredo', $this->logContents());
    }

    public function test_failed_requests_are_logged_with_route_pattern_status_and_duration_only(): void
    {
        Route::middleware('api')->get('/api/_boom/{id}', fn () => throw new RuntimeException('falha interna'));

        $this->getJson('/api/_boom/42?token=abc123&from=2026-01-01')->assertStatus(500);

        $log = $this->logContents();
        $this->assertStringContainsString('api.request.failed', $log);
        $this->assertStringContainsString('"route":"/api/_boom/{id}"', $log);
        $this->assertStringContainsString('"status":500', $log);
        $this->assertStringContainsString('"durationMs":', $log);
        $this->assertStringNotContainsString('abc123', $log);

        // A linha da métrica em si não carrega a URL real nem a query.
        $line = collect(explode("
", $log))->first(fn ($row) => str_contains($row, 'api.request.failed'));
        $this->assertStringNotContainsString('42', (string) preg_replace('/^\[[^\]]+\]/', '', (string) preg_replace('/"durationMs":\d+/', '', $line)));
    }

    public function test_slow_requests_are_logged_as_warnings_and_fast_ones_are_not(): void
    {
        Route::middleware('api')->get('/api/_slow', function () {
            usleep((LogSlowOrFailedRequests::SLOW_THRESHOLD_MS + 50) * 1000);

            return response()->json(['ok' => true]);
        });
        Route::middleware('api')->get('/api/_fast', fn () => response()->json(['ok' => true]));

        $this->getJson('/api/_fast')->assertOk();
        $this->assertStringNotContainsString('api.request', $this->logContents());

        $this->getJson('/api/_slow')->assertOk();
        $this->assertStringContainsString('api.request.slow', $this->logContents());
    }

    public function test_session_tokens_never_reach_the_log_even_when_a_request_blows_up(): void
    {
        $tenant = Tenant::factory()->create();
        $project = Project::factory()->for($tenant)->create();
        $member = Member::factory()->for($tenant)->create();
        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id, 'member_id' => $member->id,
            'project_id' => $project->id, 'role' => RoleAssignment::ROLE_MEMBER,
        ]);

        $token = app(SessionTokenService::class)->issue($tenant->id, $member->id)->token;
        [, $payload, $signature] = explode('.', $token);

        // Segredo ausente: a verificação do token lança dentro do serviço, com
        // o token como argumento — o pior caso para um trace de exceção.
        config(['timersmit.session_secret' => '']);
        $this->getJson('/api/me/timer', ['Authorization' => "Bearer {$token}"])->assertStatus(500);

        // E tokens inválidos/expirados (401) também não são gravados.
        config(['timersmit.session_secret' => 'outro-segredo-de-teste-com-mais-de-32-bytes']);
        $this->getJson('/api/me/timer', ['Authorization' => "Bearer {$token}"])->assertStatus(401);

        $log = $this->logContents();
        $this->assertStringContainsString('TIMERSMIT_SESSION_SECRET', $log, 'a exceção precisa ter sido registrada para o teste valer');
        $this->assertStringNotContainsString($payload, $log);
        $this->assertStringNotContainsString($signature, $log);
        $this->assertStringNotContainsString('Bearer', $log);
        $this->assertStringNotContainsString('outro-segredo-de-teste-com-mais-de-32-bytes', $log);
    }
}
