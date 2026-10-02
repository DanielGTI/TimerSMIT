<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use Firebase\JWT\JWT;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * T006 — prova de que a sessão do backend só existe depois de validar a
 * assinatura do token da extensão (HS256, segredo do Marketplace), nunca
 * a partir de campos soltos enviados pelo cliente. Corrigido após teste
 * real contra a organização smitbr: o token não é para chamar a API do
 * Azure DevOps (ver DevOpsIdentityVerifier) — é validado localmente.
 */
class AuthSessionTest extends TestCase
{
    use RefreshDatabase;

    private function signAppToken(string $nameid, string $tid, bool $allowed = true): string
    {
        // Os testes usam diretórios aleatórios; só os "liberados" entram na lista.
        if ($allowed) {
            config(['timersmit.allowed_aad_tenants' => [...config('timersmit.allowed_aad_tenants'), strtolower($tid)]]);
        }

        $secret = config('timersmit.extension_secret');

        return JWT::encode([
            'nameid' => $nameid,
            'tid' => $tid,
            'jti' => (string) Str::uuid(),
            'iss' => 'app.vstoken.visualstudio.com',
            'aud' => 'test-extension-audience',
            'nbf' => now()->timestamp,
            'exp' => now()->addMinutes(70)->timestamp,
        ], $secret, 'HS256');
    }

    public function test_valid_app_token_provisions_tenant_and_member_and_issues_session(): void
    {
        $userId = (string) Str::uuid();
        $aadTenantId = (string) Str::uuid();

        $response = $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken($userId, $aadTenantId),
            'claimedOrganizationId' => 'org-guid-123',
            'claimedOrganizationName' => 'contoso',
            'displayName' => 'Ada Lovelace',
        ]);

        $response->assertOk()->assertJsonStructure(['sessionToken', 'expiresAt', 'tenantId']);

        $this->assertDatabaseHas('tenants', [
            'devops_organization_id' => 'org-guid-123',
            'aad_tenant_id' => $aadTenantId,
        ]);
        $this->assertDatabaseHas('members', [
            'devops_identity_id' => $userId,
            'display_name' => 'Ada Lovelace',
        ]);

        $sessionToken = $response->json('sessionToken');
        $this->getJson('/api/me', ['Authorization' => "Bearer {$sessionToken}"])
            ->assertOk()
            ->assertJson(['displayName' => 'Ada Lovelace'])
            // Sem política cadastrada, valem os padrões; qualquer membro pode lê-los.
            ->assertJsonPath('policy.dailyLimitHours', 24)
            ->assertJsonPath('policy.retroactiveWindowDays', 30)
            ->assertJsonPath('policy.durationIncrementMinutes', 1)
            ->assertJsonPath('policy.commentRequired', false);

        // Bootstrap: primeira pessoa a conectar uma organização nova vira
        // admin dela (sem isso, ninguém teria papel para conceder acesso).
        $member = Member::query()->where('devops_identity_id', $userId)->firstOrFail();
        $this->assertDatabaseHas('role_assignments', [
            'member_id' => $member->id,
            'project_id' => null,
            'role' => RoleAssignment::ROLE_ADMIN,
        ]);
    }

    public function test_directory_outside_the_allowlist_gets_403_and_nothing_is_created(): void
    {
        $response = $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), (string) Str::uuid(), allowed: false),
            'claimedOrganizationId' => 'org-de-outra-empresa',
            'claimedOrganizationName' => 'outra-empresa',
            'displayName' => 'Alguém de fora',
        ]);

        $response->assertStatus(403)->assertJsonMissing(['sessionToken']);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Member::query()->count());
    }

    public function test_allowlist_is_case_insensitive_and_defaults_to_the_smit_directory(): void
    {
        $smit = '5517d73c-0aed-49c1-9d7e-0a38889a4fc5';
        $this->assertContains($smit, config('timersmit.allowed_aad_tenants'));

        config(['timersmit.allowed_aad_tenants' => [$smit]]);
        $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), strtoupper($smit), allowed: false),
            'claimedOrganizationId' => 'smitbr-id',
            'claimedOrganizationName' => 'smitbr',
        ])->assertOk();
    }

    public function test_an_empty_allowlist_denies_everyone(): void
    {
        config(['timersmit.allowed_aad_tenants' => []]);

        $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), (string) Str::uuid(), allowed: false),
            'claimedOrganizationId' => 'org-x',
            'claimedOrganizationName' => 'x',
        ])->assertStatus(403);
    }

    public function test_token_signed_with_wrong_secret_never_creates_tenant_or_session(): void
    {
        $forgedToken = JWT::encode([
            'nameid' => (string) Str::uuid(),
            'tid' => (string) Str::uuid(),
            'iss' => 'app.vstoken.visualstudio.com',
            'aud' => 'test-extension-audience',
            'nbf' => now()->timestamp,
            'exp' => now()->addMinutes(70)->timestamp,
        ], 'wrong-secret-not-the-real-one-but-still-32-bytes-long', 'HS256');

        $response = $this->postJson('/api/auth/session', [
            'appToken' => $forgedToken,
            'claimedOrganizationId' => 'org-guid-123',
            'claimedOrganizationName' => 'contoso',
        ]);

        $response->assertStatus(422);
        $this->assertSame(0, Tenant::query()->count());
        $this->assertSame(0, Member::query()->count());
    }

    public function test_expired_token_is_rejected(): void
    {
        $secret = config('timersmit.extension_secret');
        $expiredToken = JWT::encode([
            'nameid' => (string) Str::uuid(),
            'tid' => (string) Str::uuid(),
            'iss' => 'app.vstoken.visualstudio.com',
            'aud' => 'test-extension-audience',
            'nbf' => now()->subMinutes(120)->timestamp,
            'exp' => now()->subMinutes(60)->timestamp,
        ], $secret, 'HS256');

        $response = $this->postJson('/api/auth/session', [
            'appToken' => $expiredToken,
            'claimedOrganizationId' => 'org-guid-123',
            'claimedOrganizationName' => 'contoso',
        ]);

        $response->assertStatus(422);
    }

    public function test_reusing_organization_id_from_a_different_aad_tenant_is_rejected(): void
    {
        $response = $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), 'tenant-a'),
            'claimedOrganizationId' => 'shared-org-id',
            'claimedOrganizationName' => 'contoso',
        ]);
        $response->assertOk();

        $spoofResponse = $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), 'tenant-b'),
            'claimedOrganizationId' => 'shared-org-id',
            'claimedOrganizationName' => 'contoso (forjado)',
        ]);

        $spoofResponse->assertStatus(409);
        $this->assertSame(1, Tenant::query()->count());
    }

    public function test_second_member_joining_an_existing_tenant_does_not_get_auto_admin(): void
    {
        $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken((string) Str::uuid(), 'tenant-a'),
            'claimedOrganizationId' => 'org-guid-456',
            'claimedOrganizationName' => 'contoso',
        ])->assertOk();

        $secondUserId = (string) Str::uuid();
        $this->postJson('/api/auth/session', [
            'appToken' => $this->signAppToken($secondUserId, 'tenant-a'),
            'claimedOrganizationId' => 'org-guid-456',
            'claimedOrganizationName' => 'contoso',
        ])->assertOk();

        $secondMember = Member::query()->where('devops_identity_id', $secondUserId)->firstOrFail();
        $this->assertDatabaseMissing('role_assignments', [
            'member_id' => $secondMember->id,
        ]);
    }
}
