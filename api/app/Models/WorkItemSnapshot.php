<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cosmético — título/tipo do work item enviados pela extensão a partir do
 * form já aberto. Nunca usado para decidir autorização (isso é
 * RoleAssignment/ProjectPolicy, verificado no servidor).
 */
class WorkItemSnapshot extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'devops_work_item_id',
        'title',
        'work_item_type',
        'captured_at',
    ];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
