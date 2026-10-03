<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'devops_project_id',
        'devops_project_name',
        'is_enabled',
        'counts_as_idle',
        'uses_billable',
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'counts_as_idle' => 'boolean',
            'uses_billable' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
