<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;

/**
 * Um papel atribuído com project_id nulo vale para todos os projetos do
 * tenant (ex.: admin da organização); um papel com project_id definido só
 * vale para aquele projeto (FR-001, FR-015).
 */
class RoleAssignmentChecker
{
    public function memberHasAnyRole(Member $member, ?Project $project, array $roles): bool
    {
        return RoleAssignment::query()
            ->where('tenant_id', $member->tenant_id)
            ->where('member_id', $member->id)
            ->whereIn('role', $roles)
            ->where(function ($query) use ($project) {
                $query->whereNull('project_id');

                if ($project !== null) {
                    $query->orWhere('project_id', $project->id);
                }
            })
            ->exists();
    }
}
