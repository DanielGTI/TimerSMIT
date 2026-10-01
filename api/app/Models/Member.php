<?php

namespace App\Models;

use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Identidade Azure DevOps já confirmada (nunca criado a partir de dados
 * afirmados pelo cliente sem passar por DevOpsIdentityVerifier). Implementa
 * Authenticatable apenas para se integrar a Gate/Policy e `$request->user()`
 * — não há login por senha; a sessão vem de SessionTokenService.
 */
class Member extends Model implements AuthenticatableContract
{
    use Authenticatable;
    use HasFactory;

    protected $fillable = [
        'tenant_id',
        'devops_identity_id',
        'display_name',
        'email',
        'directory_active',
        'directory_synced_at',
    ];

    protected function casts(): array
    {
        return [
            'directory_active' => 'boolean',
            'directory_synced_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
