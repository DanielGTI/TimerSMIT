<?php

/**
 * Dados de demonstração para a verificação de backup (scripts/verify-restore.sh).
 * Roda só no contêiner de teste (usa factories, que são dependência de dev).
 */

use App\Models\Member;
use App\Models\Project;
use App\Models\RoleAssignment;
use App\Models\Tenant;
use App\Models\TimeEntry;
use App\Models\WeeklySubmission;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

foreach (['Empresa A', 'Empresa B'] as $name) {
    $tenant = Tenant::factory()->create(['devops_organization_name' => $name]);
    $project = Project::factory()->for($tenant)->create();
    $members = Member::factory()->for($tenant)->count(3)->create();

    foreach ($members as $member) {
        RoleAssignment::factory()->create([
            'tenant_id' => $tenant->id, 'member_id' => $member->id,
            'project_id' => $project->id, 'role' => RoleAssignment::ROLE_MEMBER,
        ]);
        TimeEntry::factory()->count(700)->create([
            'tenant_id' => $tenant->id, 'project_id' => $project->id, 'member_id' => $member->id,
            'note' => "Observação com acentuação e emoji 🕒 de {$member->display_name}",
        ]);
        WeeklySubmission::factory()->create([
            'tenant_id' => $tenant->id, 'member_id' => $member->id,
            'week_start_date' => '2026-09-28', 'status' => WeeklySubmission::STATUS_SUBMITTED,
        ]);
    }
}

echo 'semeado: '.TimeEntry::query()->count()." lançamentos\n";
