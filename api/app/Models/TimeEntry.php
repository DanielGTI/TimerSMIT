<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TimeEntry extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const SOURCE_TIMER = 'timer';

    public const SOURCE_MANUAL = 'manual';

    protected $fillable = [
        'tenant_id',
        'project_id',
        'member_id',
        'devops_work_item_id',
        'timer_session_id',
        'activity_type_id',
        'local_date',
        'timezone',
        'duration_seconds',
        'source',
        'billable',
        'note',
        'revision',
    ];

    /**
     * local_date fica como texto 'Y-m-d' (sem cast `date`): o cast grava
     * datetime completo e quebra comparações exatas no SQLite.
     */
    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
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

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function timerSession(): BelongsTo
    {
        return $this->belongsTo(TimerSession::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }
}
