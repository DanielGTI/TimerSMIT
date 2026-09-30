<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Tenant;
use App\Services\SessionTokenService;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sessão inválida ou expirada precisa virar 401 — é o que faz a extensão
 * renovar a sessão sozinha. Já foi 500 (exceção com argumento inexistente).
 */
class SessionExpiryTest extends TestCase
{
    use RefreshDatabase;

    private function issueFor(Tenant $tenant, Member $member): string
    {
        return app(SessionTokenService::class)->issue($tenant->id, $member->id)->token;
    }

    public function test_garbage_token_is_401_not_500(): void
    {
        $this->getJson('/api/me', ['Authorization' => 'Bearer isto-nao-e-um-jwt'])
            ->assertStatus(401)
            ->assertJson(['message' => 'Não autenticado.']);
    }

    public function test_expired_session_is_401(): void
    {
        $tenant = Tenant::factory()->create();
        $member = Member::factory()->for($tenant)->create();
        $token = $this->issueFor($tenant, $member);

        $this->travel(61)->minutes();

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertStatus(401);
    }

    public function test_session_is_valid_until_it_expires(): void
    {
        $tenant = Tenant::factory()->create();
        $member = Member::factory()->for($tenant)->create();
        $token = $this->issueFor($tenant, $member);

        $this->travel(59)->minutes();

        $this->getJson('/api/me', ['Authorization' => "Bearer {$token}"])->assertOk();
    }

    public function test_token_signed_with_another_secret_is_401(): void
    {
        $forged = JWT::encode(
            ['iat' => now()->timestamp, 'exp' => now()->addHour()->timestamp, 'tenant_id' => 1, 'member_id' => 1],
            'outro-segredo-qualquer-com-mais-de-32-bytes!!',
            'HS256',
        );

        $this->getJson('/api/me', ['Authorization' => "Bearer {$forged}"])->assertStatus(401);
    }
}
