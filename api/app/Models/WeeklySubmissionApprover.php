<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WeeklySubmissionApprover extends Model
{
    protected $fillable = [
        'weekly_submission_id',
        'revision',
        'approver_id',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(WeeklySubmission::class, 'weekly_submission_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'approver_id');
    }
}
