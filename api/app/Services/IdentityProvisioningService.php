<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Resolve tenant/membro a partir de uma identidade já confirmada pelo Azure
 * DevOps (ver DevOpsIdentityVerifier). Cria os registros na primeira vez que
 * a organização/usuário aparecem — nunca antes da verificação.
 */
class IdentityProvisioningService
{
    public function resolve(VerifiedDevOpsIdentity $identity): ProvisionedIdentity
    {
        return DB::transaction(function () use ($identity) {
            $tenant = Tenant::query()->firstOrCreate(
                ['devops_organization_id' => $identity->organizationId],
                ['devops_organization_name' => $identity->organizationName],
            );

            if ($tenant->wasRecentlyCreated === false && $tenant->devops_organization_name !== $identity->organizationName) {
                $tenant->update(['devops_organization_name' => $identity->organizationName]);
            }

            $member = Member::query()->firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'devops_identity_id' => $identity->identityId,
                ],
                ['display_name' => $identity->displayName],
            );

            if ($member->wasRecentlyCreated === false && $member->display_name !== $identity->displayName) {
                $member->update(['display_name' => $identity->displayName]);
            }

            return new ProvisionedIdentity($tenant, $member);
        });
    }
}
