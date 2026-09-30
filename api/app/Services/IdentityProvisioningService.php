<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Resolve tenant/membro a partir de uma identidade já confirmada pelo Azure
 * DevOps (ver DevOpsIdentityVerifier). Cria os registros na primeira vez que
 * a organização/usuário aparecem — nunca antes da verificação.
 *
 * A organização em si (`claimedOrganizationId`/`Name`) é uma afirmação do
 * cliente, não verificada pelo token (ver DevOpsIdentityVerifier). A defesa
 * aqui é: na primeira vez que um ID de organização aparece, ele fica
 * permanentemente associado ao tenant Entra (`aadTenantId`) verificado
 * daquela chamada. Qualquer chamada futura com o mesmo ID de organização
 * mas vindo de um tenant Entra diferente é um sinal de nome forjado/
 * reaproveitado — rejeitamos, nunca fundimos ou sobrescrevemos o dono.
 */
class IdentityProvisioningService
{
    public function resolve(
        VerifiedDevOpsIdentity $identity,
        string $claimedOrganizationId,
        string $claimedOrganizationName,
    ): ProvisionedIdentity {
        return DB::transaction(function () use ($identity, $claimedOrganizationId, $claimedOrganizationName) {
            $tenant = Tenant::query()->where('devops_organization_id', $claimedOrganizationId)->first();

            if ($tenant && $tenant->aad_tenant_id !== $identity->aadTenantId) {
                throw new TenantOwnershipMismatchException(
                    "Organização '{$claimedOrganizationId}' já pertence a outro tenant do Azure AD.",
                );
            }

            if (! $tenant) {
                $tenant = Tenant::query()->create([
                    'devops_organization_id' => $claimedOrganizationId,
                    'aad_tenant_id' => $identity->aadTenantId,
                    'devops_organization_name' => $claimedOrganizationName,
                ]);
            } elseif ($tenant->devops_organization_name !== $claimedOrganizationName) {
                $tenant->update(['devops_organization_name' => $claimedOrganizationName]);
            }

            $member = Member::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'devops_identity_id' => $identity->identityId,
                ],
                ['display_name' => $identity->identityId],
            );

            return new ProvisionedIdentity($tenant, $member);
        });
    }
}
