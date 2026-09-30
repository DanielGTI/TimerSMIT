<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use App\Models\WorkItemSnapshot;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Gate;

/**
 * Resolve o projeto do Azure DevOps (GUID) para o Project interno do tenant
 * e autoriza o acesso ao work item (T014). Projetos são conhecidos na
 * primeira vez que aparecem aqui — não existe cadastro manual antes disso —
 * mas isso não concede acesso: sem RoleAssignment, a negação por padrão
 * (FR-015 / ProjectPolicy) continua valendo.
 *
 * Título/tipo do work item são cosméticos, lidos pela extensão diretamente
 * do formulário já aberto (extension/src/lib/devops/workItems.ts) — nunca
 * usados para decidir autorização, só para exibição/auditoria.
 */
class WorkItemAccessService
{
    public function authorize(
        Tenant $tenant,
        Member $member,
        string $devopsProjectId,
        string $devopsProjectName,
        int $devopsWorkItemId,
        ?string $title = null,
        ?string $workItemType = null,
    ): Project {
        $project = Project::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'devops_project_id' => $devopsProjectId,
            ],
            [
                'devops_project_name' => $devopsProjectName,
            ],
        );

        if (! $project->is_enabled) {
            throw new AuthorizationException('Projeto desabilitado para controle de horas.');
        }

        if (Gate::forUser($member)->denies('view', $project)) {
            throw new AuthorizationException('Sem permissão neste projeto.');
        }

        if ($title !== null && $workItemType !== null) {
            WorkItemSnapshot::query()->create([
                'tenant_id' => $tenant->id,
                'project_id' => $project->id,
                'devops_work_item_id' => $devopsWorkItemId,
                'title' => $title,
                'work_item_type' => $workItemType,
                'captured_at' => Date::now(),
            ]);
        }

        return $project;
    }
}
