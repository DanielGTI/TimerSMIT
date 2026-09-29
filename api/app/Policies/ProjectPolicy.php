<?php

namespace App\Policies;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Services\RoleAssignmentChecker;

class ProjectPolicy
{
    public function __construct(private readonly RoleAssignmentChecker $roles) {}

    /**
     * Qualquer papel dentro do projeto/tenant permite leitura — a negação
     * por padrão (FR-015) acontece quando nenhuma atribuição existe, mesmo
     * que o work item/projeto exista fisicamente.
     */
    public function view(Member $member, Project $project): bool
    {
        if ($member->tenant_id !== $project->tenant_id) {
            return false;
        }

        return $this->roles->memberHasAnyRole($member, $project, [
            RoleAssignment::ROLE_MEMBER,
            RoleAssignment::ROLE_APPROVER,
            RoleAssignment::ROLE_MANAGER,
            RoleAssignment::ROLE_ADMIN,
        ]);
    }

    public function manage(Member $member, Project $project): bool
    {
        if ($member->tenant_id !== $project->tenant_id) {
            return false;
        }

        return $this->roles->memberHasAnyRole($member, $project, [
            RoleAssignment::ROLE_MANAGER,
            RoleAssignment::ROLE_ADMIN,
        ]);
    }
}
