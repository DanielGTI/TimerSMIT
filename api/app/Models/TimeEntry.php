<?php

namespace App\Models;

use App\Support\WeekCalendar;
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
        'week_start_date',
        'timezone',
        'duration_seconds',
        'started_at_utc',
        'ended_at_utc',
        'source',
        'billable',
        'note',
        'revision',
        'import_ref',
    ];

    protected static function booted(): void
    {
        // Única fonte de week_start_date: qualquer criação (timer, manual,
        // factory) passa por aqui, então nunca fica nula nem divergente.
        static::creating(function (TimeEntry $entry) {
            $entry->week_start_date ??= WeekCalendar::startOf(substr((string) $entry->local_date, 0, 10));
        });

        // Projeto que não cobra por hora: nenhuma hora dele é faturável, venha de
        // onde vier (timer, lançamento manual, edição).
        static::saving(function (TimeEntry $entry) {
            if ($entry->billable && ! Project::query()->whereKey($entry->project_id)->value('uses_billable')) {
                $entry->billable = false;
            }
        });
    }

    /**
     * local_date fica como texto 'Y-m-d' (sem cast `date`): o cast grava
     * datetime completo e quebra comparações exatas no SQLite.
     */
    protected function casts(): array
    {
        return [
            'billable' => 'boolean',
            'started_at_utc' => 'datetime',
            'ended_at_utc' => 'datetime',
        ];
    }

    /** Hora local 'HH:MM' do início (no fuso em que o lançamento foi feito); nulo se não houver horário. */
    public function localStartTime(): ?string
    {
        return $this->started_at_utc?->copy()->setTimezone($this->timezone ?: 'UTC')->format('H:i');
    }

    public function localEndTime(): ?string
    {
        return $this->ended_at_utc?->copy()->setTimezone($this->timezone ?: 'UTC')->format('H:i');
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
