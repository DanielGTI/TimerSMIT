<?php

namespace App\Services;

use App\Models\ApproverAssignment;
use App\Models\Member;
use App\Models\RoleAssignment;
use App\Models\Tenant;

/**
 * Quem pode decidir uma semana (CL-002, decisão do produto):
 *  - os aprovadores designados para a pessoa (em todos os projetos, ou nos
 *    projetos em que ela lançou na semana), resolvidos no envio; e
 *  - os administradores da organização, sempre — reserva quando não há
 *    designação e saída para semana parada (aprovador que saiu).
 * Autoaprovação: só administrador aprova a própria semana.
 */
class ApproverResolver
{
    public function __construct(private readonly RoleAssignmentChecker $roles) {}

    public function isAdmin(Member $member): bool
    {
        return $this->roles->memberHasAnyRole($member, null, [RoleAssignment::ROLE_ADMIN]);
    }

    public function tenantHasAdmin(Tenant $tenant): bool
    {
        return RoleAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('role', RoleAssignment::ROLE_ADMIN)
            ->whereNull('project_id')
            ->exists();
    }

    /**
     * @param  list<int>  $projectIds  projetos com lançamento na semana
     * @return list<int> ids de membros; nunca inclui a própria pessoa
     */
    public function designatedFor(Tenant $tenant, Member $submitter, array $projectIds): array
    {
        return ApproverAssignment::query()
            ->where('tenant_id', $tenant->id)
            ->where('member_id', $submitter->id)
            ->where('approver_id', '!=', $submitter->id)
            ->where(function ($query) use ($projectIds) {
                $query->whereNull('project_id')->orWhereIn('project_id', $projectIds);
            })
            ->pluck('approver_id')
            ->unique()
            ->values()
            ->map(fn ($id) => (int) $id)
            ->all();
    }
}
