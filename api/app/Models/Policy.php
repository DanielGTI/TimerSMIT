<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Policy extends Model
{
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'project_id',
        'version',
        'duration_increment_minutes',
        'daily_limit_hours',
        'retroactive_window_days',
        'comment_required',
        'effective_from',
    ];

    protected function casts(): array
    {
        return [
            'comment_required' => 'boolean',
            'effective_from' => 'datetime',
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
