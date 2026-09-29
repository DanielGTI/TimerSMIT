<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $tenant_id
 * @property int $member_id
 * @property int|null $project_id
 * @property string $role
 */
class RoleAssignment extends Model
{
    use HasFactory;

    public const ROLE_MEMBER = 'member';

    public const ROLE_APPROVER = 'approver';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_ADMIN = 'admin';

    protected $fillable = [
        'tenant_id',
        'member_id',
        'project_id',
        'role',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
