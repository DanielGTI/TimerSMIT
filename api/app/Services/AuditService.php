<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Member;
use App\Models\Project;
use App\Models\Tenant;
use Illuminate\Support\Facades\Date;

class AuditService
{
    /**
     * Registra um evento imutável. Deve ser chamado dentro da mesma transação
     * da mutação que ele descreve (FR-011, SC-002): se a mutação não é
     * persistida, o evento também não deve ser.
     */
    public function record(
        Tenant $tenant,
        string $action,
        string $subjectType,
        ?int $subjectId,
        ?Member $actor = null,
        ?Project $project = null,
        array $context = [],
    ): AuditEvent {
        return AuditEvent::create([
            'tenant_id' => $tenant->id,
            'project_id' => $project?->id,
            'actor_member_id' => $actor?->id,
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'context' => $context,
            'occurred_at' => Date::now(),
        ]);
    }
}
