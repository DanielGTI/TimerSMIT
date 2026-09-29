<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * T006 — prova de que a sessão do backend só existe depois de o Azure DevOps
 * confirmar o token; o cliente nunca é aceito como fonte de identidade.
 * O contrato exato de connectionData (nomes de campo) ainda depende de
 * validação contra uma organização real — ver research.md.
 */
class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_app_token_provisions_tenant_and_member_and_issues_session(): void
    {
        Http::fake([
            'dev.azure.com/*/_apis/connectionData*' => Http::response([
                'instanceId' => 'org-guid-123',
                'authenticatedUser' => [
                    'id' => 'user-guid-456',
                    'providerDisplayName' => 'Ada Lovelace',
                ],
            ], 200),
        ]);

        $response = $this->postJson('/api/auth/session', [
            'appToken' => 'fake-devops-app-token',
            'claimedOrganization' => 'contoso',
        ]);

        $response->assertOk()->assertJsonStructure(['sessionToken', 'expiresAt', 'tenantId']);

        $this->assertDatabaseHas('tenants', ['devops_organization_id' => 'org-guid-123']);
        $this->assertDatabaseHas('members', ['devops_identity_id' => 'user-guid-456']);

        $sessionToken = $response->json('sessionToken');
        $this->getJson('/api/me', ['Authorization' => "Bearer {$sessionToken}"])
            ->assertOk()
            ->assertJson(['displayName' => 'Ada Lovelace']);
    }

    public function test_rejected_token_never_creates_tenant_or_session(): void
    {
        Http::fake([
            'dev.azure.com/*/_apis/connectionData*' => Http::response(null, 401),
        ]);

        $response = $this->postJson('/api/auth/session', [
            'appToken' => 'not-a-real-token',
            'claimedOrganization' => 'contoso',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Member::query()->count());
    }
}
